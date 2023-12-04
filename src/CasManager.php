<?php namespace Sentrasoft\Cas;

use Illuminate\Support\Manager;
use phpCAS;

class CasManager extends Manager implements Contracts\Factory
{

	/**
     * The application instance.
     *
     * @var \Illuminate\Contracts\Foundation\Application
     */
    protected $app;

	/**
	 * Array for storing configuration settings.
	 */
	protected $config;

	/**
	 * Attributes used for overriding or masquerading.
	 */
	protected $_attributes = [];

	/**
	 * Boolean flag used for masquerading as a user.
	 */
	protected $_masquerading = false;

	/**
	 * String for storing CAS user.
	 */
	protected $_cas_user;

	/**
	 * Array for storing CAS user attributes.
	 */
	protected $_cas_user_attributes;

	/**
	 * @param array $config
	 */
	public function __construct($app, array $config ) {
		$this->app = $app;
		$this->parseConfig($config);

		if ( $this->config['cas_debug'] === true ) {
			phpCAS::log( 'Loaded configuration:' . PHP_EOL
			             . serialize( $config ) );
		} else {
			phpCAS::setLogger(); // Disable debug
			// phpCAS::setLogger( $this->config['cas_debug'] );
		}

		phpCAS::setVerbose( $this->config['cas_verbose_errors'] );

		// Fix for PHP 7.2.  See http://php.net/manual/en/function.session-name.php
		if ( !headers_sent() && session_id() == "" ) {
			session_name( $this->config['cas_session_name'] );

			// Harden session cookie to prevent some attacks on the cookie (e.g. XSS)
			session_set_cookie_params( $this->config['cas_session_lifetime'],
				$this->config['cas_session_path'],
				env( 'APP_DOMAIN' ),
				env( 'HTTPS_ONLY_COOKIES' ),
				$this->config['cas_session_httponly'] );
		}

		$this->configureCas( $this->config['cas_proxy'] ? 'proxy' : 'client' );

		$this->configureCasValidation();

		// set login and logout URLs of the CAS server
		phpCAS::setServerLoginURL( $this->config['cas_login_url'] );


		// If specified, this will override the URL the user will be returning to.
		if ( $this->config['cas_redirect_path'] ) {
			phpCAS::setFixedServiceURL( $this->config['cas_redirect_path'] );
		}

		phpCAS::setServerLogoutURL( $this->config['cas_logout_url'] );

		if ( $this->config['cas_masquerade'] ) {
			$this->_masquerading = true;
			phpCAS::log( 'Masquerading as user: '
			             . $this->config['cas_masquerade'] );
		}
	}

	/**
     * Get the default authentication driver name.
     *
     * @return string
     */
	public function getDefaultDriver()
	{
		return $this;
	}

	/**
	 * Configure CAS Client|Proxy
	 *
	 * @param $method
	 */
	protected function configureCas( $method = 'client' ) {

		if ( $this->config['cas_enable_saml'] ) {
			$server_type = SAML_VERSION_1_1;
		} else {
			// This allows the user to use 1.0, 2.0, etc as a string in the config
			$cas_version_str = 'CAS_VERSION_' . str_replace( '.', '_',
					$this->config['cas_version'] );

			// We pull the phpCAS constant values as this is their definition
			// PHP will generate a E_WARNING if the version string is invalid which is helpful for troubleshooting
			$server_type = constant( $cas_version_str );

			if ( is_null( $server_type ) ) {
				// This will never be null, but can be invalid values for which we need to detect and substitute.
				phpCAS::log( 'Invalid CAS version set; Reverting to defaults' );
				$server_type = CAS_VERSION_2_0;
			}
		}

		if ( ! phpCAS::isInitialized() ) {
            phpCAS::$method( $server_type, $this->config['cas_hostname'],
				(int) $this->config['cas_port'],
				$this->config['cas_uri'], $this->config['cas_service_base_url'], $this->config['cas_control_session'] );
		}

		if ( $this->config['cas_enable_saml'] ) {
			// Handle SAML logout requests that emanate from the CAS host exclusively.
			// Failure to restrict SAML logout requests to authorized hosts could
			// allow denial of service attacks where at the least the server is
			// tied up parsing bogus XML messages.
			phpCAS::handleLogoutRequests( true,
				explode( ',', $this->config['cas_real_hosts'] ) );
		}
	}

	/**
	 * Maintain backwards compatibility with config file
	 *
	 * @param array $config
	 */
	protected function parseConfig( array $config ) {

		$defaults = [
			'cas_hostname'         => '',
			'cas_session_name'     => 'cas_session',
			'cas_session_lifetime' => 7200,
			'cas_session_path'     => '/',
			'cas_control_session'  => false,
			'cas_session_httponly' => true,
			'cas_port'             => 443,
			'cas_uri'              => '/cas',
			'cas_validation'       => '',
			'cas_cert'             => '',
			'cas_proxy'            => false,
			'cas_validate_cn'      => true,
			'cas_login_url'        => '',
			'cas_logout_url'       => 'https://cas.myuniv.edu/cas/logout',
			'cas_logout_redirect'  => '',
			'cas_redirect_path'    => '',
			'cas_enable_saml'      => false,
			'cas_version'          => "3.0",
			'cas_debug'            => false,
			'cas_verbose_errors'   => false,
			'cas_masquerade'       => ''
		];

		$this->config = array_merge( $defaults, $config );
	}

	/**
	 * Configure SSL Validation
	 *
	 * Having some kind of server cert validation in production
	 * is highly recommended.
	 */
	protected function configureCasValidation() {
		if ( $this->config['cas_validation'] == 'ca'
		     || $this->config['cas_validation'] == 'self' ) {
			phpCAS::setCasServerCACert( $this->config['cas_cert'],
				$this->config['cas_validate_cn'] );
		} else {
			// Not safe (does not validate your CAS server)
			phpCAS::setNoCasServerValidation();
		}
	}

	/**
     * Redirect the user to the authentication page for the provider.
     * When the authentication is performed the callback url is invoked.
     * In that callback you can process the User and create a local entry
     * in the database
     *
     * @param string $callback_url the url to invoke when the authentication is completed
     * @return \Illuminate\Http\RedirectResponse
     */
	public function authenticate($callback_url = '/cas/callback') {
		if ( $this->isMasquerading() ) {
			return redirect($callback_url);
		}

		if(empty($callback_url) || $callback_url == '/cas/callback')
			$callback_url = url($callback_url);

		if(phpCAS::isAuthenticated()) {
            return redirect($callback_url);
        }
        else {
			return phpCAS::forceAuthentication($callback_url);
        }
	}

	/**
	 * Returns the current config.
	 *
	 * @return array
	 */
	public function getConfig() {
		return $this->config;
	}

	/**
	 * Retrieve authenticated credentials.
	 * Returns either the masqueraded account or the phpCAS user.
	 *
	 * @return string
	 */
	public function user() {
		if ( $this->isMasquerading() ) {
			return (new CasUser)->fill([
				'id' => $this->config['cas_masquerade'],
                'attributes' => $this->_attributes
            ]);
		}

		$this->setUser();
		return (new CasUser)->fill([
			'id' => $this->_cas_user,
			'attributes' => $this->_cas_user_attributes
		]);
	}

	private function setUser()
	{
		if(phpCAS::checkAuthentication()) {
			$this->_cas_user = phpCAS::getUser();
	        $this->_cas_user_attributes = phpCAS::getAttributes();

			session()->put('cas_user', $this->_cas_user);
		}
	}

	public function getCurrentUser() {
		return $this->user();
	}

	/**
	 * Retrieve a specific attribute by key name.  The
	 * attribute returned can be either a string or
	 * an array based on matches.
	 *
	 * @param $key
	 *
	 * @return mixed
	 */
	public function getAttribute( $key ) {
		if ( ! $this->isMasquerading() ) {
			if($this->check()) {
				return phpCAS::getAttribute( $key );
			}
		}
		if ( $this->hasAttribute( $key ) ) {
			return $this->_attributes[ $key ];
		}

		return;
	}

	/**
	 * Check for the existence of a key in attributes.
	 *
	 * @param $key
	 *
	 * @return boolean
	 */
	public function hasAttribute( $key ) {
		if ( $this->isMasquerading() ) {
			return array_key_exists( $key, $this->_attributes );
		}

		if($this->check()) {
			return phpCAS::hasAttribute( $key );
		}

		return false;
	}

	/**
	 * Logout of the CAS session and redirect users.
	 *
	 * @param string $url
	 * @param string $service
	 */
	public function logout( $url = '', $service = '' ) {
		if ( phpCAS::isSessionAuthenticated() ) {
			if ( isset( $_SESSION['phpCAS'] ) ) {
				$serialized = serialize( $_SESSION['phpCAS'] );
				phpCAS::log( 'Logout requested, but no session data found for user:'
				             . PHP_EOL . $serialized );
			}
		}
		$params = [];
		if ( $service ) {
			$params['service'] = $service;
		} elseif ( $this->config['cas_logout_redirect'] ) {
			$params['service'] = $this->config['cas_logout_redirect'];
		}
		if ( $url ) {
			$params['url'] = $url;
		}

		if(session()->has('cas_user')) {
            session()->forget('cas_user');
        }

		phpCAS::logout( $params );
		exit;
	}


	/**
	 * Logout the user using the provided URL.
	 *
	 * @param $url
	 */
	public function logoutWithUrl( $url ) {
		$this->logout( $url );
	}

	/**
	 * Get the attributes for for the currently connected user. This method
	 * can only be called after authenticate() or an error wil be thrown.
	 *
	 * @return mixed
	 */
	public function getAttributes() {
		if($this->isMasquerading()) {
			return $this->_attributes;
		}

		if($this->check()) {
			return phpCAS::getAttributes();
		}
	}

	/**
	 * Checks to see is user is authenticated locally
	 *
	 * @return boolean
	 */
	public function isAuthenticated() {
		return $this->isMasquerading() ? true : phpCAS::isAuthenticated();
	}

	/**
	 * Checks to see is user is globally in CAS
	 *
	 * @return boolean
	 */
	public function check() {
		return $this->isMasquerading() ? true : phpCAS::checkAuthentication();
	}

	/**
	 * Checks to see if masquerading is enabled
	 *
	 * @return bool
	 */
	public function isMasquerading() {
		return $this->_masquerading;
	}

	/**
	 * Set the attributes for a user when masquerading. This
	 * method has no effect when not masquerading.
	 *
	 * @param array $attr : the attributes of the user.
	 */
	public function setAttributes( array $attr ) {
		$this->_attributes = $attr;
		phpCAS::log( 'Forced setting of user masquerading attributes: '
		             . serialize( $attr ) );
	}

	/**
	 * Pass through undefined methods to phpCAS
	 *
	 * @param $method
	 * @param $params
	 *
	 * @return mixed
	 */
	public function __call( $method, $params ) {
		if ( method_exists( 'phpCAS', $method )
		     && is_callable( [
				'phpCAS',
				$method
			] ) ) {
			return call_user_func_array( [ 'phpCAS', $method ], $params );
		}
		throw new \BadMethodCallException( 'Method not callable in phpCAS '
		                                   . $method . '::' . print_r( $params,
				true ) );
	}
}
