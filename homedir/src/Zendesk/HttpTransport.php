<?php
declare(strict_types=1);

namespace Whisper\Zendesk;

/**
 * Ét HTTP-kald uden at følge redirects (Client følger dem selv og afgør, hvor Authorization må sendes).
 * Tests indsprøjter en falsk transport; i drift er det CurlTransport.
 */
interface HttpTransport
{
    /**
     * @param array<string, string> $headers
     * @param string|null $saveTo  skriv body i denne fil i stedet for at returnere den
     * @throws ZendeskError ved netværksfejl
     */
    public function request(string $method, string $url, array $headers = [], ?string $body = null, ?string $saveTo = null): HttpResult;
}
