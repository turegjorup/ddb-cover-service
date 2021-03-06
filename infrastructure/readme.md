# Azure kubernetes service
This document explains how to configure AKS to run Cover Service in Kubernetes with virtual node scaling and monitoring.

Log in through CLI and list available regions. You should use a region in the EU to ensure data safety.
```sh
az login
az account list-locations -o table
```

Set basic configuration variables used in this guide more than once. They are only set in the current terminal window 
used and only as long as it is not closed.
```sh
ksname=ddb-cover-service
res=CoverService 
region=westeurope
version=$(az aks get-versions -l ${region} --query 'orchestrators[-1].orchestratorVersion' -o tsv)
```

## Create services and the cluster
This setup is the more advanced AKS setup with virtual node 
(https://docs.microsoft.com/en-us/azure/aks/virtual-nodes-cli) to handle peak traffic better. This requires an AKS setup 
with advanced network setup to enable communication between ACI (Azure container instance) and the cluster.

Enable ACI for your subscription.
```sh
az provider register --namespace Microsoft.ContainerInstance
```

Create resources group 
```sh
az group create --name ${res} --location ${region}
```

Create a VNET with the two subnets that are going to be used.
```sh
az network vnet create \
    --resource-group ${res} \
    --name coverServiceVnet \
    --address-prefixes 10.0.0.0/8 \
    --subnet-name coverServiceSubnet \
    --subnet-prefix 10.240.0.0/16
```

And an additional subnet to connect to ACI.
```sh
az network vnet subnet create \
    --resource-group ${res} \
    --vnet-name coverServiceVnet \
    --name coverServiceVirtualNodeSubnet \
    --address-prefix 10.241.0.0/16
```

To connect to ACI you need a service principal assignment. So create the principal first.
```sh
az ad sp create-for-rbac --skip-assignment
```

From the output of the previous command we need to save the application id and password (app secret).
```sh
APPID=<id>
PASSWORD=<passwd>
```

Get the Azure id for the virtual network to allow it access to ACI.
```sh
VNETID=$(az network vnet show --resource-group ${res} --name coverServiceVnet --query id -o tsv)
```

Create the access rule.
```sh
az role assignment create --assignee $APPID --scope $VNETID --role Contributor
```

Get the Azure subnet id.
```sh
SUBNET=$(az network vnet subnet show --resource-group ${res} --vnet-name coverServiceVnet --name coverServiceSubnet --query id -o tsv)
```

Create the cluster and wait for it to create the 3 nodes in the standard cluster (it takes a bit of time, so go ahead 
and get yourself a cup of coffee).
```sh
az aks create \
    --resource-group ${res} \
    --name ${ksname} \
    --node-count 3 \
    --node-vm-size Standard_DS3_v2 \
    --kubernetes-version ${version} \
    --network-plugin azure \
    --service-cidr 10.0.0.0/16 \
    --dns-service-ip 10.0.0.10 \
    --docker-bridge-address 172.17.0.1/16 \
    --vnet-subnet-id $SUBNET \
    --service-principal $APPID \
    --client-secret $PASSWORD 
```

Enable the virtual node in your cluster.
````sh
az aks enable-addons \
    --resource-group ${res} \
    --name ${ksname} \
    --addons virtual-node \
    --subnet-name coverServiceVirtualNodeSubnet
````

Configure kubeclt to connect to the new cluster
```sh
az aks get-credentials --resource-group ${res} --name ${ksname}
```

Verify that you are connected to the cluster now.
```sh
kubectl get nodes
```

### Storage account

```sh
AKS_PERS_STORAGE_ACCOUNT_NAME=coverserviceprod
AKS_PERS_RESOURCE_GROUP=CoverService
AKS_PERS_LOCATION=westeurope
AKS_PERS_SHARE_NAME=coverservice
```

## Helm
We are going to use https://helm.sh/ to install ingress and cert-manager into the cluster setup. Note that we here are using helm version 3. We also install the kubectx helper tool as it makes switching cluster and namespaces easier.
```sh
brew install helm
brew install kubectx
```

Add stable official and bitnami helm charts repositories.
```sh
helm repo add stable https://kubernetes-charts.storage.googleapis.com/
helm repo add bitnami https://charts.bitnami.com/bitnami
helm repo update
```

## Ingress

Create namespace and change into the namespace.
```sh
kubectl create namespace ingress
```

Install nginx ingress using helm chart.
```sh
helm upgrade --install ingress stable/nginx-ingress --namespace ingress \
--set controller.metrics.enabled=true \
--set controller.stats.enabled=true \
--set controller.podAnnotations."prometheus\.io/scrape"=true \
--set controller.podAnnotations."prometheus\.io/port"=10254 
```

Wait for the public IP to be assigned.
```sh
watch --interval=1 kubectl --namespace ingress get services -o wide ingress-nginx-ingress-controller
```

# Certificate manager

Install the CustomResourceDefinition resources separately
```sh
kubectl apply --validate=false -f https://raw.githubusercontent.com/jetstack/cert-manager/release-0.11/deploy/manifests/00-crds.yaml
```

Create the namespace for cert-manager
```sh
kubectl create namespace cert-manager
```

Add the Jetstack Helm repository
```sh
helm repo add jetstack https://charts.jetstack.io
```

Update your local Helm chart repository cache
```sh
helm repo update
```

Install the cert-manager Helm chart to enable support for lets-encrypt.
```sh
helm install cert-manager --namespace cert-manager --version v0.13.1 jetstack/cert-manager
```

## Monitoring
To use Azure insights we need an analytics workspace to send data into. 
```sh
az resource create --resource-type Microsoft.OperationalInsights/workspaces \
 --name coverServiceDDBWorkspace \
 --resource-group ${res} \
 --location ${region} \
 --properties '{}' -o table
```
 
First get the resource id of the workspace you created, by running
```sh
workspaceresoid=$(az resource show --resource-type Microsoft.OperationalInsights/workspaces --resource-group ${res} --name coverServiceDDBWorkspace --query "id" -o tsv)
```

Next enable the monitoring add-on by running the command below.
```sh
az aks enable-addons --resource-group ${res} \
    --name ${ksname} \
    --addons monitoring \
    --workspace-resource-id ${workspaceresoid}
```

Send pod container logs to insights and enabled live view.
```yaml
apiVersion: rbac.authorization.k8s.io/v1
kind: ClusterRole
metadata:
    name: containerHealth-log-reader
rules:
    - apiGroups: ["", "metrics.k8s.io", "extensions", "apps"]
      resources:
         - "pods/log"
         - "events"
         - "nodes"
         - "pods"
         - "deployments"
         - "replicasets"
      verbs: ["get", "list"]
---
apiVersion: rbac.authorization.k8s.io/v1
kind: ClusterRoleBinding
metadata:
    name: containerHealth-read-logs-global
roleRef:
    kind: ClusterRole
    name: containerHealth-log-reader
    apiGroup: rbac.authorization.k8s.io
subjects:
- kind: User
  name: clusterUser
  apiGroup: rbac.authorization.k8s.io
```

```sh
kubectl apply -f logreader-rbac.yaml
```

See this [repository](https://github.com/itk-dev/k8s_azure_monitoring.git) for information about setting up prometheus inside the cluster.

## Install ElasticSearch operator

```sh
kubectl apply -f https://download.elastic.co/downloads/eck/1.0.0-beta1/all-in-one.yaml
```

## Horizontal pod autoscaler (HPA)
The application deployment uses HPA, which can be enabled when installing the helm chart with `--set hpa.enabled=true`.


# Application install
To install the application into the kubernetes cluster helm chars are included with the source code.

### Prepare (shard configuration)
The first step is to prepare the cluster with services that are used across the different services that makes up the complete CoverService application (frontend, upload service, faktor export, importers etc.).

```sh
kubectl create namespace cover-service
helm upgrade --install shared-config infrastructure/shared-config --namespace cover-service
```


### CoverService

```yaml
{{- if eq .Values.env "prod" }}
---
apiVersion: v1
kind: Secret
metadata:
  namespace: {{ .Release.Namespace }}
  name: {{ .Release.Name }}-secret
type: Opaque
stringData:
  APP_SECRET: "yyyyy"
{{- end }}

{{- if eq .Values.env "stg" }}
---
apiVersion: v1
kind: Secret
metadata:
  namespace: {{ .Release.Namespace }}
  name: {{ .Release.Name }}-secret
type: Opaque
stringData:
  APP_SECRET: "xxxxx"

{{- if .Values.ingress.enableAuth }}
---
apiVersion: v1
kind: Secret
metadata:
  namespace: {{ .Release.Namespace }}
  name: {{ .Release.Name }}-basic-auth
type: Opaque
data:
  auth: BASE64-ENCODED-STRING
{{- end }}
{{- end }}
```

Get the main application up and running.
```sh
helm upgrade --install cover-service infrastructure/cover-service --namespace cover-service --set hpa.enabled=true --set ingress.enableTLS=true --set ingress.mail='MAIL@itkdev.dk' --set ingress.domain=cover.dandigbib.org
```

Jump into the new namespace.
```sh
kubens cover-service
```

### The other services

* [Vendor Importers service](https://github.com/danskernesdigitalebibliotek/ddb-cover-service-importers)
* [Upload service](https://github.com/danskernesdigitalebibliotek/ddb-cover-service-upload)
* [Faktor export service](https://github.com/danskernesdigitalebibliotek/ddb-cover-service-faktor-export)

