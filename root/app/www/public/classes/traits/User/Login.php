<?php

/*
----------------------------------
------  Created: 091026   ------
------  Austin Best       ------
----------------------------------
*/

trait Login
{
    public function login($username, $password)
    {
        $username = trim($username);
        $password = trim($password);

        if ($username === '' || $password === '') {
            return false;
        }

        $row = $this->database->getUserByUsername($username);
        if (!$row) {
            return false;
        }

        $existingPassword = $row['password'] ?? '';
        if (!password_verify($password, $existingPassword)) {
            return false;
        }

        if (session_status() != PHP_SESSION_ACTIVE) {
            session_start();
        }
        $_SESSION['userdata'] = $row;
        session_write_close();

        return true;
    }
}
