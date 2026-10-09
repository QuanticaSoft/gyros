#!/usr/bin/perl
use strict;
use warnings;

# helper para logs ---------------
sub log_msg {
    my ($msg) = @_;
    my $time = localtime();
    print "[$time] $msg\n";
}


log_msg("Starting heartbeat...");
my $hb = fork();
if ($hb == 0) {
    exec("/usr/bin/perl", "/opt/gyros/agent/heartbeat.pl");
    exit;
}

log_msg("Starting usb-monitor (detecta)...");
my $um = fork();
if ($um == 0) {
    exec("/usr/bin/perl", "/opt/gyros/agent/detecta.pl");
    exit;
}


while (1) {
    log_msg("Gyros Agent alive...");
    sleep 60;
}
