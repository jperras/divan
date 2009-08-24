<?php
/**
 * Divan: A CakePHP datasource for the CouchDB (http://couchdb.apache.org/) document-oriented
 * database.
 *
 * This datasource uses PHP 5.2 specific functionality (json_encode and json_decode),
 * and is thus dependent on PHP 5.2 and greater.
 *
 * Original implementation by Garrett J. Woodworth (aka gwoo), additional code, fixes
 * and clean up by Joël Perras (http://nerderati.com).
 *
 * Copyright 2009, Joël Perras (http://nerderati.com)
 *
 * Licensed under The MIT License
 * Redistributions of files must retain the above copyright notice.
 *
 * @filesource
 * @copyright     Copyright 2009, Joël Perras (http://nerderati.com)
 * @package       app
 * @subpackage    app.model.datasources
 * @license       http://www.opensource.org/licenses/mit-license.php The MIT License
 */
App::import('Core', 'HttpSocket');

/**
 * Divan: A CakePHP datasource for the CouchDB (http://couchdb.apache.org/) document-oriented
 * database.
 *
 * @package app
 * @subpackage app.model.datasources
 * @todo Perhaps create a Document class that implements ArrayAccess, for easier internal querying
 *       of data returned from couchdb documents.
 * @todo Add permanent & temporary view generation
 * @todo Perhaps add in error handling for revision conflicts
 * @todo Implement DivanSource::calculate()
 */
class DivanSource extends DataSource {

	/**
	 * Default URI used to connect to CouchDB.
	 *
	 * All attributes can be overriden by specifying the relevant
	 * configuration options in the DivanSource constructor.
	 *
	 * @var array
	 */
	protected $_uri = array(
		'scheme' => 'http',
		'host' => 'localhost',
		'port' => '5984',
		'user' => null,
		'pass' => null
	);


	/**
	 * Message returned from CouchDB on successful default connection
	 *
	 * @const string
	 */
	const CONNECTED = 'Welcome';

	/**
	 * Constructor for DivanSource
	 *
	 * @param array   $config      Datasource configuration array
	 * @param boolean $autoConnect True if datasource should connect on instantiation,
	 *                             false otherwise.
	 * @return boolean             True on successful object instantiation & connection
	 */
	public function __construct($config = null, $autoConnect = true) {
		if (!isset($config['request'])) {
			$config['request']['uri'] = array_merge($this->_uri, $config);
		}

		parent::__construct($config);
		$this->fullDebug = Configure::read() > 1;

		return ($autoConnect) ? $this->connect() : true;
	}

	/**
	 * Connects to the database using options in the given
	 * configuration array.
	 *
	 * @param  array   $config Array of configuration parameters
	 * @return boolean         True on connection success, false otherwise
	 */
	public function connect($config = array()) {
		if ($this->connected !== true) {
			if (!empty($config)) {
				if (!isset($config['request'])) {
					$config['request']['uri'] = array_merge($this->_uri, $config);
				}
			}

			$this->setConfig($config);
			$this->Socket = new HttpSocket($this->config);
			$connection = $this->_decode($this->Socket->get());

			if (isset($connection->couchdb) && $connection->couchdb == self::CONNECTED) {
				$this->connected = true;
			}
		}

		return $this->connected;
	}

	/**
	 * Close the database connection
	 *
	 * @return boolean True on successful close, false otherwise
	 */
	public function close() {
		return $this->disconnect();
	}

	/**
	 * Disconnects from database.
	 *
	 * @return boolean True for successful disconnection
	 */
	public function disconnect() {
		$this->connected = false;
		$this->Socket = null;
		return true;
	}

	/**
	 * Lists all couchdb databases, and caches the response
	 *
	 * @param  array $data Data to be cached in parent call to DataSource::listSources().
	 * @return array       Array of couchdb sources
	 * @todo Move URI mangling to DivanSource::uri()
	 */
	public function listSources($data = null) {
		$cache = parent::listSources($data);
		if ($cache != null) {
			return $cache;
		}

		$sources = $this->_decode($this->Socket->get($this->uri() . '_all_dbs'));
		parent::listSources($sources);

		return $sources;
	}

	/**
	 * Returns a Model description (metadata) or null if none found.
	 *
	 * @param  object $model Reference to calling model
	 * @return mixed         Model metadata
	 */
	public function describe(&$model) {
		$cache = parent::describe($model);
		if ($cache != null) {
			return $cache;
		}
		$fields = (array) $this->read($model);
		return $fields;
	}

	/**
	 * Creates a document.
	 *
	 * @param object $model   Reference to calling model
	 * @param array  $fields  Fields to update
	 * @param array  $values  Values of fields to update
	 * @return string         Decoded response
	 */
	public function create(&$model, $fields = null, $values = null) {
		if ($fields !== null && $values !== null) {
			$data = array_combine($fields, $values);
		} else {
			$data = $model->data;
		}

		if (array_key_exists('id', $data)) {
			return $this->update($model, $fields, $values);
		}

		return $this->_decode($this->Socket->post($this->uri($model), $this->_encode($data)));
	}

	/**
	 * Reads a document.
	 *
	 * @param object $model     Reference to calling model
	 * @param array  $queryData Data relevant to the query. See [blank] for accepted options.
	 * @return string           Decoded response
	 * @todo Move URI mangling to DivanSource::uri()
	 */
	public function read(&$model, $queryData = array()) {
		$uri = $this->uri($model) . '/' . join('/', array_values($queryData));
		$result = $this->_decode($this->Socket->get($uri));
		return $result;
	}

	/**
	 * Updates a couchdb document, based on id.
	 *
	 * Is called when an id is set in the calling model's data.
	 *
	 * @param object $model   Reference to calling model
	 * @param array  $fields  Fields to update
	 * @param array  $values  Values of fields to update
	 * @return string         Decoded response
	 * @todo Move URI mangling to DivanSource::uri()
	 * @todo Implement parsing of rev from passed data
	 */
	public function update(&$model, $fields = null, $values = null) {
		if ($fields !== null && $values !== null) {
			$data = array_combine($fields, $values);
		} else {
			$data = $model->data;
		}

		$uri = $this->uri($model) . '/' . $data['id'];
		$record = $this->read($model, array($data['id']));
		$data['_rev'] = $record->_rev;
		unset($data['id']);

		return $this->_decode($this->Socket->put($uri, $this->_encode($data)));
	}

	/**
	 * Datasource delete
	 *
	 * Is called when deleting a document.
	 *
	 * @param object $model   Reference to calling model
	 * @param array  $fields  Fields to update
	 * @param array  $values  Values of fields to update
	 * @return string         Decoded response
	 * @todo Move URI mangling to DivanSource::uri()
	 */
	public function delete(&$model, $id = null) {
		if ($id === null) {
			$id = $model->id;
		}

		$record = $this->read($model, (array) $id);
		$uri = $this->uri($model) . '/' . $id . '?rev=' . $record->_rev;

		return $this->_decode($this->Socket->delete($uri));
	}

	/**
	 * Gets full 'table' name (including prefix, if set), based on
	 * passed model object.
	 *
	 * @param  object $model Model object reference
	 * @return string        Fully qualified 'table' name
	 */
	public function fullTableName(&$model) {
		$table = null;

		if (is_object($model)) {
			$table = $model->tablePrefix . $model->table;
		} elseif (isset($this->config['prefix'])) {
			$table = $this->config['prefix'] . strval($model);
		} else {
			$table = strval($model);
		}

		return $table;
	}

	/**
	 * Creates the URI for the model representation
	 *
	 * @param  mixed  $model Model object reference
	 * @return string        The constructed URI.
	 */
	public function uri(&$model = null) {
		return '/' . $this->fullTableName($model);
	}

	/**
	 * Encodes data for transport
	 *
	 * @param  array $data Data to be encoded
	 * @return string      Encoded data
	 */
	protected function _encode($data) {
		return json_encode($data);
	}

	/**
	 * Decodes data returned by CouchDB
	 *
	 * @param  array $data Data to be decoded
	 * @return string      Decoded data
	 */
	protected function _decode($data) {
		return json_decode($data);
	}
}

?>