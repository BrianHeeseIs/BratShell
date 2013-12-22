BratShell v0.1 Alpha
==========

PHP shell that hijacks the the incoming socket connection to provide an interactive shell session on port 80

Author: Brian Heese

Contributors: Jeroen van Rijn, Richard Clifford, Marlon Etheredge

Date: 05-08-2013

Dependencies: PHP >= 5.3.6 - 5.3.14, PHP >= 5.4.0 - 5.4.4

Inspired by: PentestMonkey's earlier work on the subject http://pentestmonkey.net/tools/web-shells/php-findsock-shell

    USAGE: 
    1: upload to apache server running php >= 5.3.6
    2: nc -v [target hostname] 80
        GET /fd.php HTTP/1.0
        
        [shell magically spawns]
        $SINIT (<-- optional in case you want a pretty PTY shell, requires python to be installed though)

How it works
==========

TODO: Fill this in

Please see PentestMonkey's post and the BratShell source for now whilst I document the inner workings of BratShell.
