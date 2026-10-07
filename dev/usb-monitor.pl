#!/usr/bin/perl
use strict;
use warnings;
use IO::Socket::INET;
use POSIX qw(strftime);

sub read_kv_file {
    my ($path) = @_;
    my %kv;
    open my $fh, "<", $path
        or die "No puedo abrir $path";
    while (<$fh>) {
        chomp;
        next if /^#/ || /^\s*$/;
        my ($k, $v) = split /=/, $_, 2;
        $kv{$k} = $v;
    }
    close $fh;
    return %kv;
}

my %cfg = read_kv_file("/opt/gyros/agent/config.conf");
my %env = read_kv_file("/opt/gyros/agent/.env");

my $AGENT_ID     = $env{AGENT_ID} or die "Falta AGENT_ID en .env";
my $BACKEND_HOST = $cfg{BACKEND_HOST};
my $BACKEND_PORT = $cfg{BACKEND_PORT};

open(my $fh, "udevadm monitor --subsystem-match=usb --property |")
    or die "No puedo iniciar udevadm";

my $current_event = "usb_add";

while (my $line = <$fh>) {

    if ($line =~ /^UDEV\s+\[\d+\.\d+\]\s+(add|remove)/) {
        $current_event = $1 eq 'remove' ? 'usb_removed' : 'usb_add';
    }

    if ($line =~ /ID_SERIAL_SHORT=(\S+)/) {

        my $device_id = $1;
        my $time      = strftime("%Y-%m-%d %H:%M:%S", localtime);

        my $json = qq|{
"agent_id":"$AGENT_ID",
"event":"$current_event",
"device_id":"$device_id",
"status":"on_line",
"lastSeen":"$time"
}|;

        my $sock = IO::Socket::INET->new(
            PeerAddr => $BACKEND_HOST,
            PeerPort => $BACKEND_PORT,
            Proto    => 'tcp',
            Timeout  => 5
        );

        if ($sock) {
            print $sock "$json\n";
            close $sock;
        }
    }
}