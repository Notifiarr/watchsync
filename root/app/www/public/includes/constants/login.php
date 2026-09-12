<?php

/*
----------------------------------
------  Created: 091026   ------
------  Austin Best       ------
----------------------------------
*/

interface LoginModes
{
    public const REQUIRED = 'required';
    public const OFF      = 'off';
    public const BYPASS   = 'bypass';
}

define('LOGIN_DEFAULT_AUTH_HEADER', 'X-Webauth-User');
