IgorShell 0.1 alpha

Author: Captain
Date: 06-08-2013
Dependancies: PHP >= 5.3.6
Description:
Findsock php shell, uses the requesting socket and binds a shell to it. The shell makes use of the file descriptors inherited from apache. 
It iterates over all sockets untill the connecting socket is found, at which point it spawns a shell and hooks their i/o streams.

Besides spawning the shell it's also able to whipe apache logs by the same abuse of inherited file descriptors.

USAGE: [from attacker machine]: 
nc -v [target machine] 80
GET /fd.php HTTP/1.0
[enter]

xx Captain
Greetz to Spike for the assist ;)
