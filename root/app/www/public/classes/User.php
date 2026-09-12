<?php

/*
----------------------------------
------  Created: 091026   ------
------  Austin Best       ------
----------------------------------
*/

loadClassTraits(RELATIVE_PATH . 'classes/traits/User/');

class User
{
    use Login;
    use Logout;
    use UserAccountSettings;

    protected $database;
    public    $userdata = [];

    public function __construct()
    {
        global $userdata, $database;

        $this->database = $database;
        $this->userdata = $userdata;
    }

    public function getUserdata($userId)
    {
        return $this->database->getUserById($userId);
    }
}
