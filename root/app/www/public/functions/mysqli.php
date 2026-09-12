<?php

/*
----------------------------------
------  Created: 091026   ------
------  Austin Best       ------
----------------------------------
*/

function abs_mysqli_query($query, $db)
{
    $queryType = substr(strtolower($query), 0, 6);

    switch ($queryType) {
        case 'select':
            return abs_mysqli_select($query, $db);
        default:
            try {
                return mysqli_query($db, $query);
            } catch (Exception $e) {

            }
            break;
    }
}

function abs_mysqli_select($query, $db)
{
    try {
        return mysqli_query($db, $query);
    } catch (Exception $e) {

    }
}

function abs_mysqli_fetch_assoc($r)
{
    $result = '';

    if ($r) {
        try {
            $result = mysqli_fetch_assoc($r);
        } catch (Exception $e) {

        }
    }

    return $result;
}
