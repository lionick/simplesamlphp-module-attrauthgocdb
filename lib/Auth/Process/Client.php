<?php

/**
 * Authproc filter for retrieving attributes from the Grid Configuration 
 * Database (GOCDB) and adding them to the list of attributes received from the
 * identity provider.
 *
 * Example configuration:
 *
 *    'authproc' => array(
 *       ...
 *       '60' => array(
 *            'class' => 'attrauthgocdb:Client',
 *            'api_base_path' => 'https://gocdb.aa.org/api',
 *            'subject_attribute' => 'distinguishedName',
 *            'role_attribute' => 'eduPersonEntitlement',
 *            'role_urn_namespace' => 'urn:mace:aa.org',
 *            'role_scope' => 'vo.org',
 *            'ssl_client_cert' => 'client_example_org.chained.pem',
 *            'ssl_verify_peer' => true,
 *       ),
 *
 * @author Nicolas Liampotis <nliam@grnet.gr>
 */
class sspmod_attrauthgocdb_Auth_Process_Client extends SimpleSAML_Auth_ProcessingFilter
{
    // Set default configuration options
    private $config = array(
        'ssl_verify_peer' => true,
    );

    public function __construct($config, $reserved)
    {
        parent::__construct($config, $reserved);
        $params = array(
            'api_base_path', 
            'subject_attribute', 
            'role_attribute',
            'role_urn_namespace',
        );
        foreach ($params as $param) {
            if (!array_key_exists($param, $config)) {
                throw new SimpleSAML_Error_Exception(
                    'Missing required configuration parameter: ' .$param);
            }
            $this->config[$param] = $config[$param];
        }
        $optional_params = array(
            'role_scope',
            'ssl_client_cert',
            'ssl_verify_peer', 
        );
        foreach ($optional_params as $optional_param) {
            if (array_key_exists($optional_param, $config)) {
                $this->config[$optional_param] = $config[$optional_param];
            }
        }
    }

    public function process(&$state)
    {
        try {
            assert('is_array($state)');
            if (!array_key_exists($this->config['subject_attribute'], $state['Attributes'])) {
                SimpleSAML_Logger::debug("[aagocdb]"
                    ." Skipping query to GOCDB AA at "
                    .$this->config['api_base_path']
                    .": No attribute named '"
                    .$this->config['subject_attribute']
                    ."' in state information.");
                return;
            }
            $t0 = round(microtime(true) * 1000); // TODO
            $subjectIds = $state['Attributes'][$this->config['subject_attribute']];
            foreach ($subjectIds as $subjectId) {
                $newAttributes = $this->getAttributes($subjectId);
                SimpleSAML_Logger::debug("[aagocdb]"
                    ." process: newAttributes="
                    .var_export($newAttributes, true));
                foreach($newAttributes as $key => $value) {
                    if (empty($value)) {
                        unset($newAttributes[$key]);
                    }
                }
                if(!empty($newAttributes)) {
                    if (!isset($state['Attributes'][$this->config['role_attribute']])) {
                        $state['Attributes'][$this->config['role_attribute']] = array();
                    }
                    $state['Attributes'][$this->config['role_attribute']] = array_merge(
                        $state['Attributes'][$this->config['role_attribute']],
                        $newAttributes[$this->config['role_attribute']]
                    );
                }
            }
            $t1 = round(microtime(true) * 1000); // TODO 
            SimpleSAML_Logger::debug(
                "[aagocdb] process: dt=" . var_export($t1-$t0, true) . "msec");
        } catch (\Exception $e) {
            $this->showException($e);
        }

    }

    public function getAttributes($subjectId)
    {
        SimpleSAML_Logger::debug('[aagocdb] getAttributes: subjectId='
            . var_export($subjectId, true));

        $attributes = array();

        // Construct GOCDB API URL
        $url = $this->config['api_base_path'] . '/?method=get_user&dn=' 
            . urlencode($subjectId);
        $data = $this->http('GET', $url);
        if ($data->count() < 1 || empty($data->{'EGEE_USER'}->{'USER_ROLE'})) {
            return $attributes;
        } 
        // Check for pagination metadata
        $pageMeta = $this->getPageMeta($data); 
        SimpleSAML_Logger::debug('[aagocdb] getAttributes pageMeta='
            .var_export($pageMeta, true));
        $attributes[$this->config['role_attribute']] = array();
        foreach($data->{'EGEE_USER'}->{'USER_ROLE'} as $user_role) {
            $value = $this->config['role_urn_namespace']
                . ':' . urlencode($user_role->{'PRIMARY_KEY'})
                . ':' . urlencode($user_role->{'ON_ENTITY'})
                . ':' . urlencode($user_role->{'USER_ROLE'});
            if (isset($this->config['role_scope'])) {
                $value .= '@' . $this->config['role_scope'];
            }
            $attributes[$this->config['role_attribute']][] = $value;
        }
        return $attributes;
    }

    private function http($method, $url)
    {
        SimpleSAML_Logger::debug("[aagocdb] http: method="
            . var_export($method, true) . ", url=" . var_export($url, true));
        $ch = curl_init($url);
        curl_setopt_array(
            $ch,
            array(
                CURLOPT_CUSTOMREQUEST => $method,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_SSL_VERIFYPEER => $this->config['ssl_verify_peer'],
                CURLOPT_CONNECTTIMEOUT => 8,
            )
        );
        if (!empty($this->config['ssl_client_cert'])) {
            curl_setopt($ch, CURLOPT_SSLCERT, 
                \SimpleSAML\Utils\Config::getCertPath($this->config['ssl_client_cert']));
        }

        // Send the request
        $response = curl_exec($ch);
        $http_response = curl_getinfo($ch, CURLINFO_HTTP_CODE);

        // Check for error; not even redirects are allowed here
        if ($http_response !== 200) {
            SimpleSAML_Logger::error("[aagocdb] API request failed: HTTP response code: "
                . $http_response . ", error message: '" . curl_error($ch)) . "'";
            throw new SimpleSAML_Error_Exception("API request failed");
        }
        $data = new SimpleXMLElement($response);
        return $data;
    }

    private function getPageMeta($response)
    {
        SimpleSAML_Logger::debug("[aagocdb] getPageMeta: response="
            . var_export($response, true));
        if (empty($response->{'meta'})) {
            return array();
        }
        $meta = $response->{'meta'};
        $result = array();
        if (!empty($meta->{'count'})) {
            $result['count'] = (int) $meta->{'count'}->__toString(); 
        }
        if (!empty($meta->{'max_page_size'})) {
            $result['max_page_size'] = (int) $meta->{'max_page_size'}->__toString(); 
        }
        foreach($meta->{'link'} as $link) {
            if (!empty($link->attributes()->{'rel'})
                && (string) $link->attributes()->{'rel'} === 'next'
                && !empty($link->attributes()->{'href'})) {
                $result['next'] = (string) $link->attributes()->{'href'};
                break;
            }
        }
        return $result;
    }

    private function showException($e)
    {
        $globalConfig = SimpleSAML_Configuration::getInstance();
        $t = new SimpleSAML_XHTML_Template($globalConfig, 'attrauthgocdb:exception.tpl.php');
        $t->data['e'] = $e->getMessage();
        $t->show();
        exit();
    }
}
