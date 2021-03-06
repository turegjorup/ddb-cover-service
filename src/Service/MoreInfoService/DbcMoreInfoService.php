<?php

namespace App\Service\MoreInfoService;

class DbcMoreInfoService extends AbstractMoreInfoService
{
    private const SERVICE_NAMESPACE = 'http://oss.dbc.dk/ns/moreinfo';
    private const WSDL = __DIR__.'/Schemas/DBC/moreInfoService.wsdl';
    private const PROVIDE_DEFAULT_COVER = false;

    protected function getNameSpace(): string
    {
        return self::SERVICE_NAMESPACE;
    }

    protected function getWsdl(): string
    {
        return self::WSDL;
    }

    protected function provideDefaultCover(): bool
    {
        return self::PROVIDE_DEFAULT_COVER;
    }
}
