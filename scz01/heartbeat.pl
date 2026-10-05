#!/usr/bin/perl
use strict;
use warnings;
$| = 1;

use LWP::UserAgent;
use HTTP::Request;
use JSON qw(encode_json);
use Time::HiRes qw(sleep);

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

my %env = read_kv_file("/opt/gyros/agent/.env");

# Configuración (AJUSTA AQUÍ)
my $HEARTBEAT_URL = 'https://www.quanticasoft.com/gyrosfe/agent/heartbeat.php';
my $AGENT_ID      = $env{AGENT_ID}    or die "Falta AGENT_ID en .env";
my $AGENT_TOKEN   = $env{AGENT_TOKEN} or die "Falta AGENT_TOKEN en .env";
my $VERSION       = '1.0.0';
my $INTERVAL       = 60;   # segundos entre cada heartbeat
my $MAX_RETRIES    = 5;
my $BACKOFF_FACTOR = 2;

# Logging
sub log_msg {
    my ($msg) = @_;
    my $time = localtime();
    print "[$time] HEARTBEAT: $msg\n";
}

log_msg("Heartbeat agent started.");
log_msg("Using URL: $HEARTBEAT_URL");

# HTTP client
my $ua = LWP::UserAgent->new(
    timeout => 15,
    agent   => "gyros-heartbeat/1.0",
);

# =====================
# Helpers: uptime / ip
# =====================
sub uptime_seconds {
    if (open my $fh, '<', '/proc/uptime') {
        my $line = <$fh>;
        close $fh;
        my ($uptime) = split ' ', ($line // '0');
        return int($uptime || 0);
    }
    return 0;
}

sub local_ip {
    my $ip = `hostname -I 2>/dev/null`;
    chomp $ip;
    my ($first) = split /\s+/, ($ip // '');
    return $first // '';
}


while (1) {
    log_msg("Heartbeat loop start.");

    my $retry   = 0;
    my $backoff = 2;      # backoff inicial corto entre reintentos
    my $success = 0;

    while ($retry < $MAX_RETRIES) {
        log_msg("Sending heartbeat attempt " . ($retry + 1) . "...");

	my %payload = (
           uptimeSec => uptime_seconds(),
    	   version   => $VERSION,
    	   localIp   => local_ip(),
	);

        #my %payload = (
        #    agent_id   => $AGENT_ID,
        #    version    => '1.0.0',
        #    timestamp  => time(),
        #);

	my $json = encode_json(\%payload);

        my $req = HTTP::Request->new('POST', $HEARTBEAT_URL);
        $req->header('Content-Type'  => 'application/json');
        $req->header('x-agent-id'    => $AGENT_ID);
        $req->header('x-agent-token' => $AGENT_TOKEN);
        #$req->content(encode_json(\%payload));
	$req->content($json);

        my $res = $ua->request($req);

        if ($res->is_success) {
            log_msg("Success: " . $res->status_line . " body=" . $res->decoded_content);
            $success = 1;
            last;
        } else {
            log_msg("Failed attempt: " . $res->status_line);
            my $body = $res->decoded_content // '';
            $body =~ s/\s+/ /g;
            log_msg("Response body: $body");
        }

        $retry++;
        sleep($backoff);
        $backoff *= $BACKOFF_FACTOR if $backoff < 60;  # límite razonable de backoff entre reintentos
    }

    unless ($success) {
        log_msg("WARNING: heartbeat failed after $MAX_RETRIES attempts");
    }

    log_msg("Sleeping $INTERVAL seconds before next heartbeat.");
    sleep($INTERVAL);
}
