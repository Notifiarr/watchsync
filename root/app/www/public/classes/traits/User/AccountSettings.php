<?php

/*
----------------------------------
------  Created: 091026   ------
------  Austin Best       ------
----------------------------------
*/

trait UserAccountSettings
{
    public function saveOwnSettings($userId, $usernameInput, $currentPassword, $newPassword, $newPasswordConfirm)
    {
        if ($userId <= 0) {
            throw new InvalidArgumentException(translate('invalidSession'));
        }

        $row = $this->getUserdata($userId);
        if (empty($row)) {
            throw new InvalidArgumentException(translate('userNotFound'));
        }

        $username = trim($usernameInput);
        if ($username === '') {
            throw new InvalidArgumentException(translate('usernameRequired'));
        }

        $usernameChanged = $username !== trim($row['username'] ?? '');

        $curPwTrim     = trim($currentPassword);
        $newPwTrim     = trim($newPassword);
        $newPwConfTrim = trim($newPasswordConfirm);
        $anyPwField    = $curPwTrim !== '' || $newPwTrim !== '' || $newPwConfTrim !== '';
        $wantPwChange  = false;
        $passwordHash  = '';

        if ($anyPwField) {
            if ($curPwTrim === '' || $newPwTrim === '' || $newPwConfTrim === '') {
                throw new InvalidArgumentException(translate('fillInPasswordFields'));
            }
            if ($newPwTrim !== $newPwConfTrim) {
                throw new InvalidArgumentException(translate('passwordsDoNotMatch'));
            }
            if (strlen($newPwTrim) < 6) {
                throw new InvalidArgumentException(translate('passwordMinLength'));
            }
            $existingPassword = $row['password'] ?? '';
            if (!password_verify($curPwTrim, $existingPassword)) {
                throw new InvalidArgumentException(translate('currentPasswordIncorrect'));
            }
            $wantPwChange = true;
            $passwordHash = password_hash($newPwTrim, PASSWORD_BCRYPT, ['cost' => 12]);
        }

        if (!$usernameChanged && !$wantPwChange) {
            throw new InvalidArgumentException(translate('nothingToUpdate'));
        }

        if ($usernameChanged) {
            $dup = $this->database->getUserByUsername($username);
            if (!empty($dup['id']) && intval($dup['id']) !== intval($userId)) {
                throw new InvalidArgumentException(translate('usernameAlreadyInUse'));
            }
        }

        $saved = $this->database->updateUserCredentials($userId, $username, $passwordHash);
        if (!$saved) {
            throw new RuntimeException(translate('couldNotSaveSettings'));
        }

        if ($usernameChanged && $wantPwChange) {
            return translate('usernameAndPasswordUpdated');
        }
        if ($usernameChanged) {
            return translate('usernameUpdated');
        }

        return translate('passwordUpdated');
    }
}
