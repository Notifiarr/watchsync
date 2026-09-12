<?php

/*
----------------------------------
------  Created: 091026   ------
------  Austin Best       ------
----------------------------------
*/

trait Logout
{
    public function logout()
    {
        if (session_status() != PHP_SESSION_ACTIVE) {
            session_start();
        }
        $_SESSION = [];
        session_unset();

        if (session_id() != '' || isset($_COOKIE[session_name()])) {
            setcookie(session_name(), '', time() - 3600, '/');
        }
        session_destroy();

        return true;
    }
}
