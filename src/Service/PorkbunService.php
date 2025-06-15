<?php

namespace App\Service;

use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\ClientExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\ServerExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\RedirectionExceptionInterface;

class PorkbunService
{
    private const API_BASE_URL = 'https://api.porkbun.com/api/json/v3';
    private $params;
    private $apiKey;
    private $secretApiKey;
    private $httpClient;

    public function __construct(ParameterBagInterface $params)
    {
        $this->params = $params;
        $this->apiKey = $_ENV['PORKBUN_API_KEY'];
        $this->secretApiKey = $_ENV['PORKBUN_SECRET_KEY'];
        $this->httpClient = HttpClient::create();
    }

    public function checkDomainAvailability(string $domain): array
    {
        try {
            $domain = trim($domain);

            if (strpos($domain, '.') === false) {
                throw new \Exception("Format de domaine invalide: $domain. Le domaine doit inclure une extension (ex: example.com)");
            }

            if (!preg_match('/^[a-zA-Z0-9][a-zA-Z0-9-]{0,61}[a-zA-Z0-9]\.[a-zA-Z]{2,}$/', $domain)) {
                throw new \Exception("Format de domaine invalide: $domain. Le format doit être 'nomdedomaine.extension'");
            }

            error_log("Vérification de disponibilité pour le domaine: $domain");

            $response = $this->httpClient->request('POST', self::API_BASE_URL . '/domain/checkDomain/' . $domain, [
                'json' => [
                    'apikey' => $this->apiKey,
                    'secretapikey' => $this->secretApiKey
                ]
            ]);

            $statusCode = $response->getStatusCode();
            $content = $response->getContent();
            $data = json_decode($content, true);

            error_log("Réponse API Porkbun pour $domain: Code $statusCode, Contenu: " . $content);

            if ($statusCode === 200 && isset($data['status']) && $data['status'] === 'SUCCESS') {
                $isAvailable = ($data['response']['avail'] ?? 'no') === 'yes';

                $price = null;
                if (isset($data['response']['price'])) {
                    $price = (float) str_replace(',', '.', $data['response']['price']);
                    error_log("Prix trouvé: $price");
                }

                return [
                    'available' => $isAvailable,
                    'avail'     => $isAvailable,
                    'price'     => $price,
                    'success'   => true
                ];
            } else {
                $errorMessage = isset($data['message']) ? $data['message'] : 'Erreur lors de la vérification du domaine (Code: ' . $statusCode . ')';
                error_log("Erreur API Porkbun: $errorMessage");
                throw new \Exception($errorMessage);
            }
        } catch (TransportExceptionInterface | ClientExceptionInterface | ServerExceptionInterface | RedirectionExceptionInterface $e) {
            $errorMessage = 'Erreur de communication avec l\'API Porkbun: ' . $e->getMessage();
            error_log($errorMessage);
            throw new \Exception($errorMessage);
        } catch (\Exception $e) {
            error_log('Exception dans PorkbunService::checkDomainAvailability: ' . $e->getMessage());
            throw $e;
        }
    }

    public function registerDomain(string $domain): array
    {
        try {
            $response = $this->httpClient->request('POST', self::API_BASE_URL . '/domain/register', [
                'json' => [
                    'apikey' => $this->apiKey,
                    'secretapikey' => $this->secretApiKey,
                    'domain' => $domain,
                    'years' => 1,
                    'whoisPrivacy' => true
                ]
            ]);

            $data = json_decode($response->getContent(), true);

            if ($data['status'] === 'SUCCESS') {
                return [
                    'success' => true,
                    'message' => 'Domaine enregistré avec succès',
                    'data' => $data
                ];
            } else {
                throw new \Exception('Erreur lors de l\'achat du domaine: ' . ($data['message'] ?? 'Erreur inconnue'));
            }
        } catch (TransportExceptionInterface | ClientExceptionInterface | ServerExceptionInterface | RedirectionExceptionInterface $e) {
            $errorMessage = 'Erreur de communication avec l\'API Porkbun: ' . $e->getMessage();
            error_log($errorMessage);
            throw new \Exception($errorMessage);
        } catch (\Exception $e) {
            error_log('Exception dans PorkbunService::registerDomain: ' . $e->getMessage());
            throw $e;
        }
    }

    public function addDnsRecord(string $domain, string $type = 'A', string $name = '@', string $content = '109.234.162.89', int $ttl = 600): array
    {
        try {
            $response = $this->httpClient->request('POST', self::API_BASE_URL . '/dns/create/' . $domain, [
                'json' => [
                    'apikey' => $this->apiKey,
                    'secretapikey' => $this->secretApiKey,
                    'name' => $name,
                    'type' => $type,
                    'content' => $content,
                    'ttl' => $ttl
                ]
            ]);

            $data = json_decode($response->getContent(), true);

            if ($data['status'] === 'SUCCESS') {
                return [
                    'success' => true,
                    'message' => 'Enregistrement DNS ajouté avec succès',
                    'data' => $data
                ];
            } else {
                throw new \Exception('Erreur lors de l\'ajout de l\'enregistrement DNS: ' . ($data['message'] ?? 'Erreur inconnue'));
            }
        } catch (TransportExceptionInterface | ClientExceptionInterface | ServerExceptionInterface | RedirectionExceptionInterface $e) {
            $errorMessage = 'Erreur de communication avec l\'API Porkbun: ' . $e->getMessage();
            error_log($errorMessage);
            throw new \Exception($errorMessage);
        } catch (\Exception $e) {
            error_log('Exception dans PorkbunService::addDnsRecord: ' . $e->getMessage());
            throw $e;
        }
    }

    public function retrieveSslCertificate(string $domain): array
    {
        try {
            $response = $this->httpClient->request('POST', self::API_BASE_URL . '/ssl/retrieve/' . $domain, [
                'json' => [
                    'apikey' => $this->apiKey,
                    'secretapikey' => $this->secretApiKey
                ]
            ]);

            $data = json_decode($response->getContent(), true);

            if ($data['status'] === 'SUCCESS') {
                return [
                    'success' => true,
                    'message' => 'Certificat SSL récupéré avec succès',
                    'key' => $data['key'] ?? null,
                    'cert' => $data['cert'] ?? null,
                    'ca' => $data['ca'] ?? null,
                    'bundle' => $data['bundle'] ?? null,
                    'expires' => $data['expires'] ?? null
                ];
            } else {
                throw new \Exception('Erreur lors de la récupération du certificat SSL: ' . ($data['message'] ?? 'Erreur inconnue'));
            }
        } catch (TransportExceptionInterface | ClientExceptionInterface | ServerExceptionInterface | RedirectionExceptionInterface $e) {
            $errorMessage = 'Erreur de communication avec l\'API Porkbun: ' . $e->getMessage();
            error_log($errorMessage);
            throw new \Exception($errorMessage);
        } catch (\Exception $e) {
            error_log('Exception dans PorkbunService::retrieveSslCertificate: ' . $e->getMessage());
            throw $e;
        }
    }
}
