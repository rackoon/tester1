<?php

class PlannerService
{
    private const DEFAULT_DISPLAY_STANDBY = 'Ootan andmeid...';
    private const DEFAULT_DISPLAY_DETECTING = 'Tuvastus kaib...';
    private const DEFAULT_DISPLAY_SUCCESS_PARKING = 'Suunata parklasse';
    private const DEFAULT_DISPLAY_SUCCESS_SERVICE = 'Suunata Service Lobby alale';
    private const DEFAULT_DISPLAY_FAILED = 'Sisenemine keelatud';

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
            if ($type === 'plate' && $this->isUnidentifiedPlateAllowedNow()) {
                $zone = 'parking';
                $this->triggerGateOpen();
                return $this->logDecision($type, $value, true, $zone, 'Ajapohine erand: E-L 08:00-19:00');
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

    private function isUnidentifiedPlateAllowedNow(): bool
    {
        $day = (int)date('N'); // 1=Mon ... 7=Sun
        if ($day < 1 || $day > 6) {
            return false;
        }

        $minutes = ((int)date('G')) * 60 + (int)date('i');
        $start = 8 * 60;
        $end = 19 * 60;

        return $minutes >= $start && $minutes < $end;
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

        if ($baseUrl === '') {
            return;
        }

        $url = rtrim($baseUrl, '/') . '?turn=on';
        $opts = ['http' => ['method' => 'GET', 'timeout' => 2]];

        if ($username !== '' && $password !== '') {
            $opts['http']['header'] = 'Authorization: Basic ' . base64_encode($username . ':' . $password);
        }

        @file_get_contents($url, false, stream_context_create($opts));
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
}
