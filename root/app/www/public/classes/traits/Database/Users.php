<?php

/*
----------------------------------
------  Created: 091026   ------
------  Austin Best       ------
----------------------------------
*/

trait Users
{
    public function getUserById($userId)
    {
        $sql = "SELECT id, username, password
                FROM " . USERS_TABLE . "
                WHERE id = " . intval($userId);
        $res = $this->query($sql);
        $row = $this->fetchAssoc($res);

        return $row ?: [];
    }

    public function getUserByUsername($username)
    {
        $sql = "SELECT id, username, password
                FROM " . USERS_TABLE . "
                WHERE username = '" . $this->prepare($username) . "'";
        $res = $this->query($sql);
        $row = $this->fetchAssoc($res);

        return $row ?: [];
    }

    public function updateUserCredentials($userId, $username, $passwordHash = '')
    {
        if ($passwordHash != '') {
            $sql = "UPDATE " . USERS_TABLE . "
                    SET username = '" . $this->prepare($username) . "',
                        password = '" . $this->prepare($passwordHash) . "'
                    WHERE id = " . intval($userId);
        } else {
            $sql = "UPDATE " . USERS_TABLE . "
                    SET username = '" . $this->prepare($username) . "'
                    WHERE id = " . intval($userId);
        }
        $res = $this->query($sql);

        return $res;
    }
}
