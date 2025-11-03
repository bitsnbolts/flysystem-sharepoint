<?php

namespace BitsnBolts\Flysystem\Sharepoint\Enums;

enum AuthType
{
    case Client_Certificate;
    case Client_Credentials;
    case User_Credentials;
}
