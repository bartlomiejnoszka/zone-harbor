<?php

declare(strict_types=1);

namespace OvhDns\Health;

final class MailChecker
{
    public function check(string $domain, array $settings, DnsSession $dns, Report $report): void
    {
        $mxAnswer = $dns->query($domain, 'MX'); $mx = $mxAnswer->values($domain, 'MX');
        $null = in_array('0 ', $mx, true) || in_array('0', $mx, true);
        $expect = $settings['mail'] ?? null;
        $mail = $expect === 'google' || $expect === 'external' || ($expect !== 'none' && $mx !== [] && !$null);
        $status = !$mxAnswer->known() ? Status::UNKNOWN : ($expect === 'none' ? ($null ? Status::PASS : Status::WARN) : ($mail && ($mx === [] || $null) ? Status::FAIL : ($mail ? Status::PASS : Status::SKIP)));
        if ($expect === 'google' && $mx !== []) foreach ($mx as $value) if (!preg_match('/^\d+ (?:smtp\.google\.com|(?:alt[1-4]\.)?aspmx\.l\.google\.com|aspmx[2-5]\.googlemail\.com)$/D', $value)) $status = Status::FAIL;
        if ($null && count($mx) !== 1) $status = Status::FAIL;
        $report->add(new Finding('mail.mx', $status, $null ? 'Null MX declares that this domain does not receive mail.' : ($mail ? 'Mail routing records inspected.' : 'No mail service expected or inferred.'), ['records' => $mx, 'expectation' => $expect], 'Google Public DNS', $status === Status::FAIL ? 'Verify the intended mail provider and its MX records.' : ''));
        if ($mail && !$null) foreach (array_slice($mx, 0, 6) as $value) {
            $host = explode(' ', $value, 2)[1] ?? '';
            if ($host === '') continue;
            $a = $dns->query($host, 'A'); $alias = $a->values($host, 'CNAME'); $addresses = $dns->addresses($host);
            $report->add(new Finding('mail.mx_target.'.$host, !$a->known() ? Status::UNKNOWN : ($alias !== [] || $addresses === [] ? Status::FAIL : Status::PASS),
                'MX targets must resolve directly to addresses.', ['host' => $host, 'addresses' => $addresses, 'cname' => $alias], 'public DNS', 'Use the mail provider hostname, not a CNAME alias.'));
        }
        $txt = $dns->query($domain, 'TXT'); $values = $txt->values($domain, 'TXT');
        $spf = array_values(array_filter($values, static fn (string $value): bool => preg_match('/^v=spf1(?:\s|$)/i', $value) === 1));
        if (!$txt->known()) $spfStatus = Status::UNKNOWN;
        elseif (count($spf) > 1) $spfStatus = Status::FAIL;
        elseif ($spf === []) $spfStatus = $mail ? Status::WARN : Status::SKIP;
        else $spfStatus = $this->spf($domain, $spf[0], $dns, $report);
        $report->add(new Finding('mail.spf', $spfStatus, 'SPF publication and syntax inspected; delivery alignment is not tested.', ['records' => $spf], 'public DNS', in_array($spfStatus, [Status::FAIL, Status::WARN], true) ? 'Publish exactly one SPF policy containing all actual sending services.' : ''));
        $dmarcName = '_dmarc.'.$domain;
        $answer = $dns->query($dmarcName, 'TXT');
        $dmarc = array_values(array_filter($answer->values($dmarcName, 'TXT'), static fn (string $v): bool => preg_match('/^v=DMARC1(?:;|$)/i', $v) === 1));
        $dmarcStatus = !$answer->known() ? Status::UNKNOWN : (count($dmarc) > 1 ? Status::FAIL : ($dmarc === [] ? ($mail ? Status::WARN : Status::SKIP) : Status::PASS));
        $tags = $dmarc === [] ? [] : self::tags($dmarc[0]);
        if (count($dmarc) === 1) {
            if ($tags === null || ($tags['v'] ?? '') !== 'DMARC1' || !in_array($tags['p'] ?? '', ['none', 'quarantine', 'reject'], true)) $dmarcStatus = Status::FAIL;
            elseif ($tags['p'] === 'none' || !isset($tags['rua'])) $dmarcStatus = Status::WARN;
            if (isset($tags['pct']) && (!ctype_digit($tags['pct']) || (int) $tags['pct'] > 100)) $dmarcStatus = Status::FAIL;
            foreach (['adkim', 'aspf'] as $tag) if (isset($tags[$tag]) && !in_array($tags[$tag], ['r', 's'], true)) $dmarcStatus = Status::FAIL;
            foreach (['rua', 'ruf'] as $tag) foreach (isset($tags[$tag]) ? explode(',', $tags[$tag]) : [] as $uri) {
                if (!preg_match('/^mailto:([^@\s]+)@([^!\s]+)(?:![0-9]+[kmgt]?)?$/iD', trim($uri), $m)) { $dmarcStatus = Status::FAIL; continue; }
                if (strtolower($m[2]) !== $domain && !str_ends_with(strtolower($m[2]), '.'.$domain)) {
                    $authorization = $domain.'._report._dmarc.'.strtolower($m[2]);
                    $auth = $dns->query($authorization, 'TXT');
                    $authorized = array_filter($auth->values($authorization, 'TXT'), static fn (string $v): bool => str_starts_with($v, 'v=DMARC1')) !== [];
                    $report->add(new Finding('mail.dmarc.reporting.'.$m[2], !$auth->known() ? Status::UNKNOWN : ($authorized ? Status::PASS : Status::WARN), 'External DMARC report destination authorization inspected.', ['record' => $authorization], 'public DNS', $authorized ? '' : 'Confirm that the report provider authorizes this domain.'));
                }
            }
        }
        $report->add(new Finding('mail.dmarc', $dmarcStatus, 'DMARC policy inspected; p=none monitors without enforcement.', ['records' => $dmarc, 'tags' => $tags], 'public DNS', $dmarcStatus === Status::WARN ? 'Review aggregate reports before increasing enforcement; configure an authorized rua destination.' : ''));
        $selectors = $settings['dkim_selectors'] ?? [];
        if ($selectors === []) $report->add(new Finding('mail.dkim', Status::SKIP, 'No DKIM selector configured. Selectors cannot be reliably discovered.', remediation: 'Configure the selectors supplied by the sending service.'));
        foreach (array_slice($selectors, 0, 8) as $selector) {
            $name = $selector.'._domainkey.'.$domain; $answer = $dns->query($name, 'TXT');
            $keys = $answer->values($name, 'TXT');
            if ($keys === [] && ($cname = $answer->values($name, 'CNAME')) !== []) { $answer = $dns->query($cname[0], 'TXT'); $keys = $answer->values($cname[0], 'TXT'); }
            $valid = count($keys) === 1 && ($tags = self::tags($keys[0])) !== null && ($tags['p'] ?? '') !== '' && in_array($tags['v'] ?? 'DKIM1', ['DKIM1'], true);
            if ($valid) {
                $key = base64_decode($tags['p'], true);
                $valid = $key !== false && (($tags['k'] ?? 'rsa') === 'ed25519' ? strlen($key) === 32 : (($tags['k'] ?? 'rsa') === 'rsa' && @openssl_pkey_get_public("-----BEGIN PUBLIC KEY-----\n".chunk_split($tags['p'], 64)."-----END PUBLIC KEY-----\n") !== false));
            }
            $report->add(new Finding('mail.dkim.'.$selector, !$answer->known() ? Status::UNKNOWN : ($valid ? Status::PASS : Status::FAIL), 'Configured DKIM selector inspected; a published key does not prove signing is enabled.', ['name' => $name, 'records' => $keys], 'public DNS', $valid ? 'Verify a delivered message to confirm DKIM signing and DMARC alignment.' : 'Publish the current public key from your sending service and enable signing.'));
        }
        if (count($selectors) > 8) $report->add(new Finding('mail.dkim.limit', Status::UNKNOWN, 'Only the first eight configured selectors were inspected.'));
        if ($mail) $report->add(new Finding('mail.delivery', Status::SKIP, 'DNS alone cannot prove delivered SPF, DKIM and DMARC pass.', remediation: 'Send a message to an independent recipient and inspect Authentication-Results.'));
    }

    private function spf(string $domain, string $policy, DnsSession $dns, Report $report, array $visited = [], int &$lookups = 0): Status
    {
        if (isset($visited[$domain])) return Status::FAIL;
        $visited[$domain] = true; $status = Status::PASS; $terminal = false;
        $terms = preg_split('/\s+/', trim($policy)) ?: [];
        if (strtolower(array_shift($terms) ?? '') !== 'v=spf1') return Status::FAIL;
        $modifiers = [];
        foreach ($terms as $term) {
            if ($terminal) $status = Status::WARN;
            if (str_contains($term, '=')) {
                [$modifier, $value] = explode('=', $term, 2); $modifier = strtolower($modifier);
                if (!preg_match('/^[a-z][a-z0-9_.-]*$/D', $modifier)) return Status::FAIL;
                if (isset($modifiers[$modifier]) || $value === '') return Status::FAIL;
                $modifiers[$modifier] = true;
                if ($modifier !== 'redirect') continue;
                $mechanism = 'redirect'; $argument = $value;
            } else {
                if (!preg_match('/^[+?~-]?(all|include|a|mx|ptr|ip4|ip6|exists)(?::([^\s]+))?(\/\d+(?:\/\/\d+)?)?$/iD', $term, $m)) return Status::FAIL;
                $mechanism = strtolower($m[1]); $argument = $m[2] ?? '';
                if (in_array($mechanism, ['include', 'exists', 'ip4', 'ip6'], true) && $argument === '') return Status::FAIL;
                if ($mechanism === 'all') { if ($argument !== '' || isset($m[3])) return Status::FAIL; $terminal = true; if (strtolower($term) === '+all' || strtolower($term) === 'all') $status = Status::WARN; }
                if (in_array($mechanism, ['ip4', 'ip6'], true)) {
                    $parts = explode('/', $argument); $flags = $mechanism === 'ip4' ? FILTER_FLAG_IPV4 : FILTER_FLAG_IPV6;
                    if (!filter_var($parts[0], FILTER_VALIDATE_IP, $flags) || (isset($parts[1]) && (!ctype_digit($parts[1]) || (int) $parts[1] > ($mechanism === 'ip4' ? 32 : 128)))) return Status::FAIL;
                }
            }
            if (in_array($mechanism, ['a', 'mx', 'ptr', 'exists'], true) && ++$lookups > 10) return Status::FAIL;
            if (!in_array($mechanism, ['include', 'redirect'], true)) continue;
            if (++$lookups > 10) return Status::FAIL;
            if (str_contains($argument, '%')) { $status = Status::UNKNOWN; continue; }
            $argument = strtolower(rtrim($argument, '.'));
            if (strlen($argument) > 253 || !preg_match('/^(?:[a-z0-9_-]{1,63}\.)+[a-z0-9-]{1,63}$/iD', $argument)) return Status::FAIL;
            $answer = $dns->query($argument, 'TXT');
            if (!$answer->known()) { $status = Status::UNKNOWN; continue; }
            $included = array_values(array_filter($answer->values($argument, 'TXT'), static fn (string $v): bool => preg_match('/^v=spf1(?:\s|$)/i', $v) === 1));
            if (count($included) !== 1) return Status::FAIL;
            $child = $this->spf($argument, $included[0], $dns, $report, $visited, $lookups);
            if ($child === Status::FAIL) return Status::FAIL;
            if ($child === Status::UNKNOWN) $status = Status::UNKNOWN;
            elseif ($child === Status::WARN && $status === Status::PASS) $status = Status::WARN;
        }
        return $status;
    }

    public static function tags(string $text): ?array
    {
        $tags = [];
        foreach (explode(';', $text) as $part) {
            if (trim($part) === '') continue;
            if (!preg_match('/^\s*([a-zA-Z][a-zA-Z0-9_]*)\s*=\s*(.*?)\s*$/sD', $part, $m) || array_key_exists(strtolower($m[1]), $tags)) return null;
            $tags[strtolower($m[1])] = $m[2];
        }
        return $tags;
    }
}
