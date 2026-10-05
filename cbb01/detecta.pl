#!/usr/bin/env perl
use strict;
use warnings;

use JSON qw(encode_json);
use LWP::UserAgent;
use HTTP::Request;
use Sys::Hostname;
use POSIX qw(strftime);
use Time::HiRes qw(usleep);
use IO::Select;
use Fcntl qw(F_GETFL F_SETFL O_NONBLOCK);

# =========================
# CONFIG
# =========================
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

my $API_URL     = 'https://www.quanticasoft.com/gyrosfe/agent/usb_event.php';
my $AGENT_ID    = $env{AGENT_ID}    or die "Falta AGENT_ID en .env";
my $AGENT_TOKEN = $env{AGENT_TOKEN} or die "Falta AGENT_TOKEN en .env";

# re-enumeración (MTP / cambiar modo USB)
my $DISCONNECT_GRACE_SEC      = 15;
my $DISCONNECT_GRACE_EXT_SEC  = 15;  # una extensión extra si parece re-enum

my $ua = LWP::UserAgent->new(timeout => 10, agent => 'gyros-usb-agent/2.3');
my $hostname = hostname();
$| = 1;

# =========================
# Helpers
# =========================
sub log_msg {
  my ($msg) = @_;
  my $time = localtime();
  print "[$time] USB: $msg\n";
}

sub iso_utc { strftime("%Y-%m-%dT%H:%M:%SZ", gmtime) }

sub send_event {
  my (%data) = @_;
  my $req = HTTP::Request->new('POST', $API_URL);
  $req->header('Content-Type'  => 'application/json');
  $req->header('x-agent-id'    => $AGENT_ID);
  $req->header('x-agent-token' => $AGENT_TOKEN);
  $req->content(encode_json(\%data));

  my $res = $ua->request($req);
  if ($res->is_success) {
    log_msg("Sent OK: " . $res->status_line);
    return 1;
  } else {
    log_msg("Sent FAIL: " . $res->status_line . " body=" . ($res->decoded_content // ''));
    return 0;
  }
}

sub sysfs_read_first_line {
  my ($file) = @_;
  return '' if !$file;
  open(my $fh, '<', $file) or return '';
  my $line = <$fh>;
  close($fh);
  return '' if !defined $line;
  chomp($line);
  return $line;
}

# /devices/.../usb3/3-3  ->  3-3
# /devices/.../usb3/3-3:1.0 -> 3-3
sub port_from_devpath {
  my ($devpath) = @_;
  return '' if !$devpath;
  my ($last) = ($devpath =~ m{/([^/]+)$});
  return '' if !$last;
  $last =~ s/:\d+\.\d+$//;
  return $last;
}

# ¿Existe el puerto o sus sub-nodos?
sub port_still_present_any {
  my ($port) = @_;
  return 0 if !$port;

  my @candidates = (
    "/sys/bus/usb/devices/$port",
    glob("/sys/bus/usb/devices/$port:*"),
    glob("/sys/bus/usb/devices/$port.*"),
  );

  for my $p (@candidates) {
    next if !$p;
    return 1 if -e $p;
  }
  return 0;
}

# Buscar el serial en TODO sysfs (para detectar re-enum a otro puerto)
# Retorna (found, new_port_guess)
sub serial_present_anywhere {
  my ($serial) = @_;
  return (0, '') if !$serial;

  for my $sf (glob("/sys/bus/usb/devices/*/serial")) {
    my $s = sysfs_read_first_line($sf);
    next if $s eq '';
    next if $s ne $serial;

    # sf: /sys/bus/usb/devices/3-3/serial  -> port = 3-3
    my $port = $sf;
    $port =~ s{/serial$}{};
    $port =~ s{.*/}{};
    return (1, $port);
  }
  return (0, '');
}

sub sysfs_vendor_for_port  { my ($p)=@_; return '' if !$p; return sysfs_read_first_line("/sys/bus/usb/devices/$p/idVendor"); }
sub sysfs_product_for_port { my ($p)=@_; return '' if !$p; return sysfs_read_first_line("/sys/bus/usb/devices/$p/idProduct"); }

# =========================
# Estado
# =========================
my %connected_serial;     # serial => 1
my %port_to_serial;       # port => serial
my %serial_info;          # serial => {vendor,product,last_port,last_path,last_seen_ts}

# pending por puerto:
# port => { t, serial, path, last_vendor, last_product, phase }
# phase 0 = grace normal, phase 1 = extensión aplicada
my %pending_disconnect;

sub remember_serial_info {
  my (%p) = @_;
  my $serial  = $p{serial}  // '';
  my $vendor  = $p{vendor}  // '';
  my $product = $p{product} // '';
  my $port    = $p{port}    // '';
  my $path    = $p{path}    // '';

  return if $serial eq '';
  $serial_info{$serial}{vendor}       = $vendor  if $vendor  ne '';
  $serial_info{$serial}{product}      = $product if $product ne '';
  $serial_info{$serial}{last_port}    = $port    if $port    ne '';
  $serial_info{$serial}{last_path}    = $path    if $path    ne '';
  $serial_info{$serial}{last_seen_ts} = time();
}

sub cancel_pending_for_port {
  my ($port, $why) = @_;
  return if !$port;
  return if !exists $pending_disconnect{$port};
  log_msg("CANCEL pending disconnect port=$port reason=$why");
  delete $pending_disconnect{$port};
}

sub flush_pending {
  my $now = time();

  for my $port (keys %pending_disconnect) {
    my $p = $pending_disconnect{$port};
    my $t = $p->{t} // 0;
    my $phase = $p->{phase} // 0;

    my $limit = ($phase == 0) ? $DISCONNECT_GRACE_SEC : $DISCONNECT_GRACE_EXT_SEC;
    next if ($now - $t) < $limit;

    my $serial = $p->{serial} // ($port_to_serial{$port} // '');

    # 1) Si el puerto sigue presente, NO es unplug
    if (port_still_present_any($port)) {
      cancel_pending_for_port($port, "port still present in sysfs");
      next;
    }

    # 2) Si el serial existe en otro lado del sysfs, es re-enum -> cancelar y actualizar port map
    if ($serial ne '') {
      my ($found, $new_port) = serial_present_anywhere($serial);
      if ($found) {
        $port_to_serial{$new_port} = $serial if $new_port ne '';
        $serial_info{$serial}{last_port} = $new_port if $new_port ne '';
        cancel_pending_for_port($port, "serial still present elsewhere (re-enum) new_port=$new_port");
        next;
      }
    }

    # 3) Si no encontramos nada, pero estamos en fase 0, extender UNA VEZ
    if ($phase == 0) {
      $pending_disconnect{$port}{t}     = time();
      $pending_disconnect{$port}{phase} = 1;
      log_msg("EXTEND pending disconnect port=$port (possible slow re-enum) +${DISCONNECT_GRACE_EXT_SEC}s");
      next;
    }

    # 4) Confirmar disconnect real
    if ($serial && $connected_serial{$serial}) {
      my $vendor  = $serial_info{$serial}{vendor}  // ($p->{last_vendor}  // '');
      my $product = $serial_info{$serial}{product} // ($p->{last_product} // '');
      my $path    = $serial_info{$serial}{last_path} // ($p->{path} // '');

      delete $connected_serial{$serial};
      delete $port_to_serial{$port};

      log_msg("USB DISCONNECT serial=$serial vendor=$vendor product=$product port=$port path=$path (confirmed)");
      send_event(
        action   => 'disconnect',
        agent_id => $AGENT_ID,
        hostname => $hostname,
        serial   => $serial,
        vendor   => $vendor,
        product  => $product,
        path     => $path,
        ts       => iso_utc(),
      );
    } else {
      log_msg("USB DISCONNECT confirmed port=$port but serial unknown; nothing sent");
    }

    delete $pending_disconnect{$port};
  }
}

# =========================
# Reconciliación periódica: sincroniza sysfs con el estado en memoria
# Corre al arrancar y cada $RECONCILE_INTERVAL segundos en el loop.
# Detecta tanto conexiones como desconexiones que udevadm pudo haber perdido.
# =========================
my $RECONCILE_INTERVAL = 30;   # segundos entre cada reconciliación
my $last_reconcile     = 0;

sub reconcile_scan {
    my %sysfs_now;

    # Leer todos los seriales reales conectados en este momento
    for my $serial_file (glob("/sys/bus/usb/devices/*/serial")) {
        my $serial = sysfs_read_first_line($serial_file);
        next if $serial eq '';
        # Ignorar controladores internos del bus USB (formato 0000:00:xx.x)
        next if $serial =~ /^[0-9a-f]{4}:[0-9a-f]{2}:[0-9a-f]{2}\.[0-9a-f]$/i;

        my $port = $serial_file;
        $port =~ s{/serial$}{};
        $port =~ s{.*/}{};
        $sysfs_now{$serial} = $port;
    }

    # — Nuevas conexiones: en sysfs pero no en memoria —
    for my $serial (keys %sysfs_now) {
        next if $connected_serial{$serial};

        my $port    = $sysfs_now{$serial};
        my $vendor  = sysfs_read_first_line("/sys/bus/usb/devices/$port/idVendor");
        my $product = sysfs_read_first_line("/sys/bus/usb/devices/$port/idProduct");
        my $devpath = readlink("/sys/bus/usb/devices/$port") // '';
        $devpath =~ s{^\.\./\.\.}{/devices} if $devpath ne '';

        $connected_serial{$serial} = 1;
        $port_to_serial{$port}     = $serial;
        remember_serial_info(
            serial  => $serial, vendor  => $vendor,
            product => $product, port   => $port, path => $devpath,
        );

        log_msg("RECONCILE CONNECT serial=$serial vendor=$vendor product=$product port=$port");
        send_event(
            action   => 'connect',   agent_id => $AGENT_ID,
            hostname => $hostname,   serial   => $serial,
            vendor   => $vendor,     product  => $product,
            path     => $devpath,    ts       => iso_utc(),
        );
    }

    # — Desconexiones perdidas: en memoria pero ya no en sysfs —
    for my $serial (keys %connected_serial) {
        next if exists $sysfs_now{$serial};
        # No interferir con desconexiones que ya están en gracia pendiente
        next if grep { ($pending_disconnect{$_}{serial} // '') eq $serial } keys %pending_disconnect;

        my $info    = $serial_info{$serial} // {};
        my $port    = $info->{last_port} // '';
        my $vendor  = $info->{vendor}    // '';
        my $product = $info->{product}   // '';
        my $devpath = $info->{last_path} // '';

        delete $connected_serial{$serial};
        delete $port_to_serial{$port} if $port ne '';

        log_msg("RECONCILE DISCONNECT serial=$serial vendor=$vendor product=$product port=$port");
        send_event(
            action   => 'disconnect', agent_id => $AGENT_ID,
            hostname => $hostname,    serial   => $serial,
            vendor   => $vendor,      product  => $product,
            path     => $devpath,     ts       => iso_utc(),
        );
    }
}

# =========================
# udev monitor (no bloqueante)
# =========================
log_msg("Starting USB monitor...");
open(my $udev, '-|', 'udevadm monitor --udev --subsystem-match=usb --property')
  or die "Cannot start udevadm monitor: $!";

my $flags = fcntl($udev, F_GETFL, 0) or die "fcntl(F_GETFL) failed: $!";
fcntl($udev, F_SETFL, $flags | O_NONBLOCK) or die "fcntl(F_SETFL) failed: $!";

my $sel = IO::Select->new();
$sel->add($udev);

reconcile_scan();   # escaneo inmediato al arrancar
$last_reconcile = time();
log_msg("Esperando eventos USB...");

my $buf = '';
my $action = '';
my %props;

while (1) {
  flush_pending();

  # Reconciliación periódica cada $RECONCILE_INTERVAL segundos
  my $now_t = time();
  if ($now_t - $last_reconcile >= $RECONCILE_INTERVAL) {
      reconcile_scan();
      $last_reconcile = $now_t;
  }

  my @ready = $sel->can_read(1.0);
  next if !@ready;

  my $chunk = '';
  my $n = sysread($udev, $chunk, 8192);
  if (!defined $n) { usleep(20_000); next; }
  if ($n == 0) { log_msg("udevadm monitor ended; exiting"); last; }

  $buf .= $chunk;

  while ($buf =~ s/^(.*)\n//) {
    my $line = $1;
    chomp($line);

    # add/remove/change
    if ($line =~ /^(UDEV|KERNEL)\s+\[[^\]]+\]\s+(add|remove|change)\b/) {
      $action = $2;
      %props  = ();
      next;
    }

    if ($line =~ /^([A-Z0-9_]+)=(.*)$/) {
      $props{$1} = $2;
      next;
    }

    # fin evento
    if ($line eq '' && $action ne '') {
      my $devtype = $props{DEVTYPE} // '';
      my $devpath = $props{DEVPATH} // '';
      my $port    = port_from_devpath($devpath);

      # cualquier add/change en el mismo puerto cancela pending
      if (($action eq 'add' || $action eq 'change') && $port ne '') {
        cancel_pending_for_port($port, "$action event on same port");
      }

      # SOLO reportamos usb_device (no interfaces)
      if ($devtype ne 'usb_device') {
        $action = ''; %props = (); next;
      }

      if ($devpath =~ /:\d+\.\d+$/) {
        $action = ''; %props = (); next;
      }

      my $vendor  = $props{ID_VENDOR_ID}    // '';
      my $product = $props{ID_MODEL_ID}     // '';
      my $serial  = $props{ID_SERIAL_SHORT} // '';

      # fallback sysfs (a veces udev no lo trae)
      if ($port ne '') {
        $serial  = sysfs_read_first_line("/sys/bus/usb/devices/$port/serial")  if $serial  eq '';
        $vendor  = sysfs_vendor_for_port($port)  if $vendor  eq '';
        $product = sysfs_product_for_port($port) if $product eq '';
      }

      if ($serial eq '' && $action eq 'remove' && $port ne '' && exists $port_to_serial{$port}) {
        $serial = $port_to_serial{$port};
      }

      if ($serial eq '') {
        log_msg("IGNORED usb_device event (no serial) action=$action port=$port path=$devpath");
        $action = ''; %props = (); next;
      }

      remember_serial_info(
        serial  => $serial,
        vendor  => $vendor,
        product => $product,
        port    => $port,
        path    => $devpath,
      );

      if ($action eq 'add') {
        if (!$connected_serial{$serial}) {
          $connected_serial{$serial} = 1;
          log_msg("USB CONNECT serial=$serial vendor=$vendor product=$product port=$port path=$devpath");
          send_event(
            action   => 'connect',
            agent_id => $AGENT_ID,
            hostname => $hostname,
            serial   => $serial,
            vendor   => $vendor,
            product  => $product,
            path     => $devpath,
            ts       => iso_utc(),
          );
        } else {
          log_msg("USB ADD seen but already connected serial=$serial (update only) port=$port");
        }
        $port_to_serial{$port} = $serial if $port ne '';
      }
      elsif ($action eq 'remove') {
        # pending por puerto (no resetear tiempo)
        if ($port ne '' && $connected_serial{$serial} && !exists $pending_disconnect{$port}) {
          $pending_disconnect{$port} = {
            t            => time(),
            serial        => $serial,
            path          => $devpath,
            last_vendor   => $vendor,
            last_product  => $product,
            phase         => 0,
          };
          log_msg("USB REMOVE seen serial=$serial (pending disconnect in ${DISCONNECT_GRACE_SEC}s) port=$port path=$devpath");
        }
      }
      elsif ($action eq 'change') {
        log_msg("USB CHANGE seen serial=$serial port=$port (no report)");
      }

      $action = '';
      %props  = ();
      next;
    }
  }
}

exit 0;
