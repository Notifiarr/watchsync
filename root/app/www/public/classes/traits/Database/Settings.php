<?php

/*
----------------------------------
------  Created: 091226   ------
------  Austin Best       ------
----------------------------------
*/

trait Settings
{
    public function getSettings()
    {
        $settings = [];

        $sql = "SELECT name, value
                FROM " . SETTINGS_TABLE;
        $res = $this->query($sql);
        while ($row = $this->fetchAssoc($res)) {
            $settings[$row['name']] = $row['value'];
        }

        return $settings;
    }

    public function getSetting($name)
    {
        if (!is_array($this->settingsCache)) {
            $this->settingsCache = $this->getSettings();
        }

        return $this->settingsCache[$name] ?? '';
    }

    public function settingEnabled($name)
    {
        return $this->getSetting($name) == '1';
    }

    public function getJsonSetting($name)
    {
        $state = json_decode($this->getSetting($name), true);

        return is_array($state) ? $state : [];
    }

    public function setJsonSetting($name, $state)
    {
        $this->setSetting($name, json_encode(is_array($state) ? $state : []));
    }

    public function setSetting($name, $value)
    {
        $sql = "SELECT name
                FROM " . SETTINGS_TABLE . "
                WHERE name = '" . $this->prepare($name) . "'";
        $res = $this->query($sql);
        $row = $this->fetchAssoc($res);

        if ($row) {
            $sql = "UPDATE " . SETTINGS_TABLE . "
                    SET value = '" . $this->prepare($value) . "'
                    WHERE name = '" . $this->prepare($name) . "'";
            $res = $this->query($sql);
        } else {
            $sql = "INSERT INTO " . SETTINGS_TABLE . "
                    (`name`, `value`)
                    VALUES
                    ('" . $this->prepare($name) . "', '" . $this->prepare($value) . "')";
            $res = $this->query($sql);
        }
        if (is_array($this->settingsCache)) {
            $this->settingsCache[$name] = $value;
        }

        return $res;
    }

    public function setSettings($settings)
    {
        foreach ($settings as $name => $value) {
            $this->setSetting($name, $value);
        }
    }
}
