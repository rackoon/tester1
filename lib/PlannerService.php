<?php

class PlannerService
{
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
            return $this->logDecision($type, $value, false, null, 'Luba puudub');
        }

        if (!$this->isScheduleAllowed($rule['schedule'])) {
            return $this->logDecision($type, $value, false, null, 'Luba ei kehti sellel ajal');
        }

        $zone = $hasReservation ? 'service_lobby' : $rule['zone'];
        $this->triggerGateOpen();

        return $this->logDecision($type, $value, true, $zone, 'Värav avatud');
    }

    private function findMatchingRule(string $type, string $value): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM access_rules WHERE subject_type = ? AND subject_value = ? ORDER BY id DESC LIMIT 1');
        $stmt->execute([$type, $value]);
        return $stmt->fetch() ?: null;
    }

    private function fetchFromPartnerSystem(string $type, string $value): ?array
    {
        $url = $this->config['partner_api'] . '?' . http_build_query(['type' => $type, 'value' => $value]);
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
        $shelly = $this->config['shelly'];
        $url = rtrim($shelly['base_url'], '/') . '?turn=on';
        $opts = ['http' => ['method' => 'GET', 'timeout' => 2]];

        if (!empty($shelly['username']) && !empty($shelly['password'])) {
            $opts['http']['header'] = 'Authorization: Basic ' . base64_encode($shelly['username'] . ':' . $shelly['password']);
        }

        @file_get_contents($url, false, stream_context_create($opts));
    }

    private function logDecision(string $type, string $value, bool $allowed, ?string $zone, string $reason): array
    {
        $stmt = $this->pdo->prepare('INSERT INTO entries(input_type,input_value,allowed,zone,reason,created_at) VALUES (?,?,?,?,?,?)');
        $stmt->execute([$type, $value, $allowed ? 1 : 0, $zone, $reason, date(DATE_ATOM)]);

        return [
            'allowed' => $allowed,
            'zone' => $zone,
            'reason' => $reason,
            'display_message' => $allowed
                ? ($zone === 'service_lobby' ? 'Suunata Service Lobby alale' : 'Suunata parklasse')
                : 'Sisenemine keelatud',
        ];
    }
}
