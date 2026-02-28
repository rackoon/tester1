<?php

class PlannerService
{
    private const DEFAULT_DISPLAY_STANDBY = 'Ootan andmeid...';
    private const DEFAULT_DISPLAY_DETECTING = 'Tuvastus kaib...';
    private const DEFAULT_DISPLAY_SUCCESS_PARKING = 'Suunata parklasse';
    private const DEFAULT_DISPLAY_SUCCESS_SERVICE = 'Suunata Service Lobby alale';
    private const DEFAULT_DISPLAY_FAILED = 'Sisenemine keelatud';
    private const DEFAULT_AMPRON_STANDBY_TEXT = 'Service Lobby';

    public function __construct(private PDO $pdo, private array $config)
    {
    }

    public function processPlate(string $plate, bool $hasReservation = false): array
    {
        return $this->process('plate', strtoupper(trim($plate)), $hasReservation);
    }

    public function processPhone(string $phone, bool $hasReservation = false): array
    {
        return $this->process('phone', preg_replace('/\s+/', '', $phone), $hasReservation);
    }

    private function process(string $type, string $value, bool $hasReservation): array
    {
        $rule = $this->findMatchingRule($type, $value);

        if (!$rule) {
            $rule = $this->fetchFromPartnerSystem($type, $value);
        }

        if (!$rule) {
            $exception = $this->findMatchingException($type, $value);
            if ($exception) {
                $zone = (string)($exception['zone'] ?? 'parking');
                $this->triggerGateOpen();
                $reason = 'Ajapohine erand: ' . (string)($exception['name'] ?? 'nimetu');
                return $this->logDecision($type, $value, true, $zone, $reason);
            }
            return $this->logDecision($type, $value, false, null, 'Luba puudub');
        }

        if (!$this->isScheduleAllowed($rule['schedule'])) {
            return $this->logDecision($type, $value, false, null, 'Luba ei kehti sellel ajal');
        }

        $zone = $hasReservation ? 'service_lobby' : $rule['zone'];
        $this->triggerGateOpen();

        return $this->logDecision($type, $value, true, $zone, 'Värav avatud');
    }

    private function findMatchingException(string $type, string $value): ?array
    {
        $stmt = $this->pdo->prepare(
            "SELECT * FROM access_exceptions
             WHERE enabled = 1
               AND input_type = ?
               AND target = 'no_permit'
             ORDER BY id DESC"
        );
        $stmt->execute([$type]);
        $rows = $stmt->fetchAll();
        foreach ($rows as $row) {
            $schedule = (string)($row['schedule'] ?? '');
            if ($this->isScheduleAllowed($schedule)) {
                return $row;
            }
        }
        return null;
    }

    private function findMatchingRule(string $type, string $value): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM access_rules WHERE subject_type = ? AND subject_value = ? ORDER BY id DESC LIMIT 1');
        $stmt->execute([$type, $value]);
        return $stmt->fetch() ?: null;
    }

    private function fetchFromPartnerSystem(string $type, string $value): ?array
    {
        $partnerApi = $this->getSetting('partner_api_url', (string)($this->config['partner_api'] ?? ''));
        if ($partnerApi === '') {
            return null;
        }

        $url = $partnerApi . '?' . http_build_query(['type' => $type, 'value' => $value]);
        $resp = @file_get_contents($url);
        if (!$resp) {
            return null;
        }
        $data = json_decode($resp, true);
        if (!is_array($data) || empty($data['allowed'])) {
            return null;
        }

        $stmt = $this->pdo->prepare('INSERT INTO access_rules(subject_type,subject_value,schedule,zone,source,created_at) VALUES (?,?,?,?,?,?)');
        $stmt->execute([
            $type,
            $value,
            $data['schedule'] ?? '24/7',
            $data['zone'] ?? 'parking',
            'partner',
            date(DATE_ATOM),
        ]);

        return [
            'schedule' => $data['schedule'] ?? '24/7',
            'zone' => $data['zone'] ?? 'parking',
        ];
    }

    public function isScheduleAllowed(string $schedule): bool
    {
        $schedule = trim(mb_strtoupper($schedule));
        if ($schedule === '24/7') {
            return true;
        }

        if (preg_match('/^WEEK:([1-7](?:,[1-7])*)\|TIME:(\d{2}):(\d{2})-(\d{2}):(\d{2})$/', $schedule, $m)) {
            $days = array_map('intval', explode(',', $m[1]));
            $startMinutes = ((int)$m[2]) * 60 + (int)$m[3];
            $endMinutes = ((int)$m[4]) * 60 + (int)$m[5];
            $nowDay = (int)date('N');
            $nowMinutes = ((int)date('G')) * 60 + (int)date('i');

            if (!in_array($nowDay, $days, true)) {
                return false;
            }

            if ($startMinutes === $endMinutes) {
                return true;
            }

            if ($startMinutes < $endMinutes) {
                return $nowMinutes >= $startMinutes && $nowMinutes < $endMinutes;
            }

            return $nowMinutes >= $startMinutes || $nowMinutes < $endMinutes;
        }

        if (!preg_match('/^([EMTRLNPK]-[EMTRLNPK])\s+(\d{1,2})-(\d{1,2})$/u', $schedule, $m)) {
            return false;
        }

        $daysMap = ['E' => 1, 'T' => 2, 'K' => 3, 'N' => 4, 'R' => 5, 'L' => 6, 'P' => 7];
        $startDay = $daysMap[$m[1][0]] ?? null;
        $endDay = $daysMap[$m[1][2]] ?? null;
        $startHour = (int)$m[2];
        $endHour = (int)$m[3];

        if ($startDay === null || $endDay === null) {
            return false;
        }

        $nowDay = (int)date('N');
        $nowHour = (int)date('G');

        $dayAllowed = $startDay <= $endDay
            ? ($nowDay >= $startDay && $nowDay <= $endDay)
            : ($nowDay >= $startDay || $nowDay <= $endDay);

        return $dayAllowed && $nowHour >= $startHour && $nowHour < $endHour;
    }

    private function triggerGateOpen(): void
    {
        $shelly = $this->config['shelly'] ?? [];
        $baseUrl = $this->getSetting('shelly_base_url', (string)($shelly['base_url'] ?? ''));
        $username = $this->getSetting('shelly_username', (string)($shelly['username'] ?? ''));
        $password = $this->getSetting('shelly_password', (string)($shelly['password'] ?? ''));
        $mode = strtolower($this->getSetting('shelly_mode', 'auto'));
        $switchIdRaw = $this->getSetting('shelly_switch_id', '0');
        $toggleAfterRaw = $this->getSetting('shelly_toggle_after', '1');

        if ($baseUrl === '') {
            return;
        }

        $switchId = max(0, (int)$switchIdRaw);
        $toggleAfter = max(0, (int)$toggleAfterRaw);
        if (!in_array($mode, ['auto', 'rpc', 'relay'], true)) {
            $mode = 'auto';
        }

        if ($mode === 'rpc' || $mode === 'auto') {
            if ($this->triggerShellyRpc($baseUrl, $switchId, $toggleAfter, $username, $password)) {
                return;
            }
            if ($mode === 'rpc') {
                return;
            }
        }

        $this->triggerShellyRelay($baseUrl, $switchId, $toggleAfter, $username, $password);
    }

    private function triggerShellyRpc(string $baseUrl, int $switchId, int $toggleAfter, string $username, string $password): bool
    {
        $root = $this->normalizeShellyRootUrl($baseUrl);
        $url = rtrim($root, '/') . '/rpc/Switch.Set?id=' . $switchId . '&on=true';
        if ($toggleAfter > 0) {
            $url .= '&toggle_after=' . $toggleAfter;
        }
        return $this->shellyHttpGet($url, $username, $password);
    }

    private function triggerShellyRelay(string $baseUrl, int $switchId, int $toggleAfter, string $username, string $password): bool
    {
        $trimmed = rtrim($baseUrl, '/');
        if (preg_match('#/relay/\d+$#', $trimmed)) {
            $url = $trimmed . '?turn=on';
        } else {
            $url = $this->normalizeShellyRootUrl($baseUrl) . '/relay/' . $switchId . '?turn=on';
        }
        if ($toggleAfter > 0) {
            $url .= '&timer=' . $toggleAfter;
        }
        return $this->shellyHttpGet($url, $username, $password);
    }

    private function normalizeShellyRootUrl(string $baseUrl): string
    {
        $url = rtrim($baseUrl, '/');
        $url = preg_replace('#/relay/\d+$#', '', $url) ?? $url;
        $url = preg_replace('#/rpc/?$#', '', $url) ?? $url;
        return rtrim($url, '/');
    }

    private function shellyHttpGet(string $url, string $username, string $password): bool
    {
        if (!function_exists('curl_init')) {
            return false;
        }
        $ch = curl_init($url);
        if ($ch === false) {
            return false;
        }
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 3,
            CURLOPT_CONNECTTIMEOUT => 2,
            CURLOPT_FAILONERROR => false,
        ]);
        if ($username !== '' && $password !== '') {
            curl_setopt($ch, CURLOPT_HTTPAUTH, CURLAUTH_ANY);
            curl_setopt($ch, CURLOPT_USERPWD, $username . ':' . $password);
        }
        curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        return $code >= 200 && $code < 300;
    }

    private function getSetting(string $key, string $default = ''): string
    {
        try {
            $stmt = $this->pdo->prepare('SELECT value FROM app_settings WHERE key = ?');
            $stmt->execute([$key]);
            $value = $stmt->fetchColumn();
            if ($value === false || $value === null || $value === '') {
                return $default;
            }
            return (string)$value;
        } catch (Throwable) {
            return $default;
        }
    }

    private function logDecision(string $type, string $value, bool $allowed, ?string $zone, string $reason): array
    {
        $stmt = $this->pdo->prepare('INSERT INTO entries(input_type,input_value,allowed,zone,reason,created_at) VALUES (?,?,?,?,?,?)');
        $stmt->execute([$type, $value, $allowed ? 1 : 0, $zone, $reason, date(DATE_ATOM)]);

        $displayMessage = $this->buildDisplayMessage($allowed, $zone);
        $this->triggerAmpronDisplay($type, $value, $allowed, $zone);
        return [
            'allowed' => $allowed,
            'zone' => $zone,
            'reason' => $reason,
            'display_message' => $displayMessage,
        ];
    }

    private function buildDisplayMessage(bool $allowed, ?string $zone): string
    {
        if (!$allowed) {
            return $this->getSetting('display_failed_text', self::DEFAULT_DISPLAY_FAILED);
        }

        if ($zone === 'service_lobby') {
            return $this->getSetting('display_success_service_text', self::DEFAULT_DISPLAY_SUCCESS_SERVICE);
        }

        return $this->getSetting('display_success_parking_text', self::DEFAULT_DISPLAY_SUCCESS_PARKING);
    }

    private function triggerAmpronDisplay(string $type, string $value, bool $allowed, ?string $zone): void
    {
        $enabled = $this->getSetting('ampron_enabled', '0') === '1';
        if (!$enabled) {
            return;
        }

        $baseUrl = trim($this->getSetting('ampron_base_url', (string)(($this->config['ampron'] ?? [])['base_url'] ?? '')));
        $displayId = trim($this->getSetting('ampron_display_id', (string)(($this->config['ampron'] ?? [])['display_id'] ?? 'SERVICE_LOBBY')));
        $username = trim($this->getSetting('ampron_username', (string)(($this->config['ampron'] ?? [])['username'] ?? '')));
        $password = trim($this->getSetting('ampron_password', (string)(($this->config['ampron'] ?? [])['password'] ?? '')));
        $timeout = max(1, (int)$this->getSetting('ampron_timeout', '3'));

        if ($baseUrl === '' || $displayId === '') {
            return;
        }

        $isServicePlate = $allowed && $zone === 'service_lobby' && $type === 'plate' && trim($value) !== '';
        if ($isServicePlate) {
            $layout = trim($this->getSetting('ampron_plate_layout', 'vehiclenumber'));
            $field = trim($this->getSetting('ampron_plate_field', 'plate'));
            $content = strtoupper(trim($value));
            $extra = trim($this->getSetting('ampron_plate_extra_query', ''));
        } else {
            $layout = trim($this->getSetting('ampron_standby_layout', 'service_lobby'));
            $field = trim($this->getSetting('ampron_standby_field', 'text'));
            $content = $this->getSetting('ampron_standby_text', self::DEFAULT_AMPRON_STANDBY_TEXT);
            $extra = trim($this->getSetting('ampron_standby_extra_query', ''));
        }

        if ($layout === '' || $field === '') {
            return;
        }

        $query = [
            'id' => $displayId,
            'layout' => $layout,
            $field => $content,
        ];
        foreach ($this->parseExtraQueryParams($extra) as $k => $v) {
            $query[$k] = $v;
        }

        $this->ampronHttpGet($baseUrl, $query, $username, $password, $timeout);
    }

    private function parseExtraQueryParams(string $raw): array
    {
        if ($raw === '') {
            return [];
        }
        $out = [];
        parse_str($raw, $out);
        if (!is_array($out)) {
            return [];
        }
        $flat = [];
        foreach ($out as $k => $v) {
            if (!is_string($k) || $k === '') {
                continue;
            }
            if (is_scalar($v) || $v === null) {
                $flat[$k] = (string)$v;
            }
        }
        return $flat;
    }

    private function ampronHttpGet(string $baseUrl, array $query, string $username, string $password, int $timeout): bool
    {
        if (!function_exists('curl_init')) {
            return false;
        }

        $url = $this->normalizeAmpronEndpoint($baseUrl) . '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
        $ch = curl_init($url);
        if ($ch === false) {
            return false;
        }

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_CONNECTTIMEOUT => min(2, $timeout),
            CURLOPT_FAILONERROR => false,
        ]);
        if ($username !== '' && $password !== '') {
            curl_setopt($ch, CURLOPT_HTTPAUTH, CURLAUTH_ANY);
            curl_setopt($ch, CURLOPT_USERPWD, $username . ':' . $password);
        }
        curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        return $code >= 200 && $code < 300;
    }

    private function normalizeAmpronEndpoint(string $baseUrl): string
    {
        $trimmed = rtrim($baseUrl, '/');
        if (str_ends_with($trimmed, '/mlds')) {
            return $trimmed;
        }
        return $trimmed . '/mlds';
    }
}
