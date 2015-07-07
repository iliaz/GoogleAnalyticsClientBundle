<?php

namespace Kairos\GoogleAnalyticsClientBundle\Consumer;

use Guzzle\Http\Client as HttpClient;
use Kairos\GoogleAnalyticsClientBundle\AuthClient\AuthClientInterface;
use Kairos\GoogleAnalyticsClientBundle\Exception\GoogleAnalyticsException;

/**
 * Class Request
 * @package Kairos\GoogleAnalyticsClientBundle\Consumers
 */
class Request implements RequestInterface
{
    /** @var \Kairos\GoogleAnalyticsClientBundle\Consumer\QueryInterface */
    protected $query;

    /** @var \Kairos\GoogleAnalyticsClientBundle\AuthClient\AuthClientInterface */
    protected $authClient;

    /** @var \Guzzle\Http\Client */
    protected $httpClient;

    /**
     * @var array
     */
    protected $userIpTable;

    /**
     * Constructor which initialize the query access token with the auth client.
     *
     * @param QueryInterface $query
     * @param AuthClientInterface $authClient
     */
    public function __construct(QueryInterface $query, AuthClientInterface $authClient)
    {
        $this->authClient = $authClient;
        $this->query = $query;
        $this->setHttpClient(new HttpClient());
        $this->userIpTable = array();

        $this->query->setAccessToken($this->authClient->getAccessToken());
    }

    /**
     * @return array
     */
    public function getResult()
    {
        return $this->mergeResults($this->getGAResult());
    }

    /**
     * Send http request to Googla analytics and gets the response.
     *
     * @param string $requestUrl
     *
     * @throws \Kairos\GoogleAnalyticsClientBundle\Exception\GoogleAnalyticsException
     *
     * @return mixed
     */
    protected function request($baseUrl, $params)
    {
        $client = $this->getHttpClient();
        $request = $client->get($baseUrl, array(), array('query' => $params));
        $response = $request->send();

        if ($response->getStatusCode() != 200) {
            throw GoogleAnalyticsException::invalidQuery($response->getReasonPhrase());
        }

        $data = json_decode($response->getBody(), true);

        return $data;
    }

    /**
     * Check if the data has pagination and gets all the data if there is a pagination.
     *
     * @return array
     */
    protected function getGAResult()
    {
        $start = microtime(true);

        $results = array();
        $requestUrls = $this->query->build();
        foreach ($requestUrls as $queryParams) {

            if(isset($queryParams['userIp']) && !isset($this->userIpTable[$queryParams['userIp']])) {
                $this->userIpTable[$queryParams['userIp']] = 10;
            }

            $data = $this->request($this->query->getBaseUrlApi(), $queryParams);
            $this->userIpTable[$queryParams['userIp']]--;
            $results[] = $data;

            $startIndex = $data['query']['start-index'];
            while (($data['totalResults'] >= $startIndex * $data['query']['max-results'])) {
                $subQueryParams = $queryParams;
                $subQueryParams['start-index'] = $startIndex;
                $results[] = $this->request($this->query->getBaseUrlApi(), $subQueryParams);
                $this->userIpTable[$subQueryParams['userIp']]--;
                $startIndex++;
                if($this->userIpTable[$subQueryParams['userIp']] === 0) {
                    $dt = 1000000-(microtime(true) - $start);
                    if($dt > 0) {
                        usleep($dt);
                        $this->userIpTable[$subQueryParams['userIp']] = 10;
                        $start = microtime(true);
                    }
                }
                unset($subQueryParams);
            }

            // if we do 10 requests in less than 1 second, we wait a little bit to match google api rate limit
            if($this->userIpTable[$queryParams['userIp']] === 0) {
                $dt = 1000000-(microtime(true) - $start);
                if($dt > 0) {
                    usleep($dt);
                    $this->userIpTable[$queryParams['userIp']] = 10;
                    $start = microtime(true);
                }
            }
        }

        $this->userIpTable = array();

        return $results;
    }

    /**
     * Merge all the datas in the basic format.
     *
     * @param $results
     *
     * @return array
     */
    protected function mergeResults($results)
    {
        $mergedResults = array();

        // Then merge result
        if (count($results) > 0) {
            // Init a $data var with the first result in order to initialize the structure
            $mergedResults = $results[0];

            // data that we want to merge are rows and totals for all results
            $totalsForAllResults = array();
            $rows = array();

            foreach ($results as $result) {
                empty($result['rows']) ? $result['rows'] = array() : null;
                $rows = array_merge_recursive($rows, $result['rows']);

                // Do the merge for the total result only on a first page if there is pagination
                if ($result['query']['start-index'] == 1) {
                    $totalsForAllResults = array_merge_recursive($totalsForAllResults, $result['totalsForAllResults']);
                }
            }

            // Set the final data with the merged values
            $mergedResults['rows'] = $rows;

            // Set the merged and sumed total
            foreach ($totalsForAllResults as $metric => $value) {
                $mergedResults['totalsForAllResults'][$metric] = is_array($value) ? array_sum($value) : $value;
            }
        }
        return $mergedResults;
    }

    /**
     * Sets an http client in order to make google analytics request.
     *
     * @param \Guzzle\Http\Client $httpClient
     *
     * @return \Kairos\GoogleAnalyticsClientBundle\Consumer\Request
     */
    public function setHttpClient(HttpClient $httpClient)
    {
        $this->httpClient = $httpClient;

        return $this;
    }

    /**
     * Gets an http client in order to make google analytics request.
     *
     * @return \Guzzle\Http\Client $httpClient
     */
    public function getHttpClient()
    {
        return $this->httpClient;
    }
}
