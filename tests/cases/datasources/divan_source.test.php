<?php
/**
 * Divan Datasource Test
 *
 * Test cases for the CakePHP Divan CouchDB datasource.
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

/**
 * Import relevant classes for testing
 */
App::import('Model', 'DivanSource');
App::import('HttpSocket');

/**
 * Generate Mock Model
 */
Mock::generate('AppModel', 'MockPost');

/**
 * Divan Datasource test class
 *
 * @package       app
 * @subpackage    app.model.datasources
 */
class DivanSourceTest extends CakeTestCase {

	/**
	 * Sets up the environment for each test method
	 *
	 * @return void
	 */
	function startTest() {
		$config = array(
			'datasource' => 'Divan',
			'database' => false,
			'persistent' => false,
			'host' => 'localhost',
			'port' => '5984',
			'login' => 'root',
			'password' => '',
		);

		$this->Divan = new DivanSource($config);
		ConnectionManager::create('Divan', $config);

		$this->Post = ClassRegistry::init('MockPost');
		$this->Post->table = 'posts';
		$this->Post->setDataSource('Divan');

		// Quick fixtures
		$this->Socket = new HttpSocket("http://{$config['host']}:{$config['port']}/");
		$this->Socket->put('/posts', array());
		$this->Socket->post('/posts', json_encode(array('testkey' => 'testvalue')));

	}

	/**
	 * Destroys the environment after each test method is run
	 *
	 * @return void
	 */
	function endTest() {
		ClassRegistry::flush();
		$this->Socket->delete('/posts', array());
	}

	/**
	 * Tests the connected status of the Divan DataSource
	 *
	 * @return void
	 */
	function testConnected() {
		$this->assertTrue($this->Divan->connected);
	}

	/**
	 * Tests the connect method of the Divan DataSource
	 *
	 * @return void
	 */
	function testConnect() {
		$result = $this->Divan->connect();
		$this->assertTrue($result);

		$this->Divan->connected = false;
		$this->Divan->Socket = null;
		$result = $this->Divan->connect();
		$this->assertTrue($result);
		$this->assertTrue($this->Divan->Socket instanceof HttpSocket);

		$config = array('host' => 'newhost', 'port' => '8888');
		$this->Divan->connected = false;
		$this->Divan->Socket = null;

		$result = $this->Divan->connect($config);
		$this->assertTrue($this->Divan->Socket instanceof HttpSocket);
		$this->assertTrue($this->Divan->config['host'], $config['host']);
		$this->assertTrue($this->Divan->config['port'], $config['port']);
	}

	/**
	 * Tests the disconnect method of the Divan DataSource
	 *
	 * @return void
	 */
	function testDisconnect() {
		$result = $this->Divan->disconnect();
		$this->assertTrue($result);
		$this->assertFalse($this->Divan->connected);
	}

	/**
	 * Tests the listSources method of the Divan DataSource
	 *
	 * @return void
	 */
	function testListSources() {
		$results = $this->Divan->listSources();
		$this->assertTrue(in_array('posts', $results));
	}

	/**
	 * Tests the fullTableName method of the Divan DataSource
	 *
	 * @return void
	 */
	function testFullTableName() {
		$result = $this->Divan->fullTableName($this->Post);
		$this->assertEqual($result, 'posts');

		$this->Post->tablePrefix = 'prefix_';
		$result = $this->Divan->fullTableName($this->Post);
		$this->assertEqual($result, 'prefix_posts');
	}

	/**
	 * Tests the describe method of the Divan DataSource
	 *
	 * @return void
	 */
	function testDescribe() {
		$result = $this->Divan->describe($this->Post);
		$this->assertEqual('posts', $result['db_name']);
	}

	/**
	 * Tests the read method of the Divan DataSource
	 *
	 * @return void
	 */
	function testRead() {
		$all = $this->Divan->read($this->Post, array('_all_docs'));
		$this->assertTrue(count($all->rows) == 1);

		$result = $this->Divan->read($this->Post, array('id' => $all->rows[0]->id));
		$this->assertEqual($result->_id, $all->rows[0]->id);
	}

	/**
	 * Tests the create method of the Divan DataSource
	 *
	 * @return void
	 */
	function testCreate() {
		$this->Post->data = array('title' => 'this is a post');
		$result = $this->Divan->create($this->Post);
		$this->assertTrue(!empty($result->ok));
		$this->assertTrue(!empty($result->id));

		$this->Post->data = null;
		$result = $this->Divan->create($this->Post, array('title'), array('this is a post'));
		$this->assertTrue(!empty($result->ok));
		$this->assertTrue(!empty($result->id));
	}

	/**
	 * Tests the create method of the Divan DataSource
	 *
	 * @return void
	 */
	function testCreateWithSpecifiedId() {
		$this->Socket->put('/posts/uniquekey', json_encode(array('title' => 'this is a put')));
		$result = json_decode($this->Socket->get('/posts/uniquekey'));
		$this->assertEqual($result->_id, 'uniquekey');
		$this->assertEqual($result->title, 'this is a put');

		$result = $this->Divan->create($this->Post, array('id', 'title'), array('uniquekey', 'Another modification'));
		$this->assertEqual($result->ok, 1);
		$this->assertEqual($result->id, 'uniquekey');

		$result = json_decode($this->Socket->get('/posts/uniquekey'));
		$this->assertEqual($result->_id, 'uniquekey');
		$this->assertEqual($result->title, 'Another modification');

	}

	/**
	 * Tests the update method of the Divan DataSource
	 *
	 * @return void
	 */
	function testUpdate() {
		$this->Socket->put('/posts/uniquekey', json_encode(array('title' => 'this is a put')));
		$result = json_decode($this->Socket->get('/posts/uniquekey'));
		$this->assertEqual($result->_id, 'uniquekey');
		$this->assertEqual($result->title, 'this is a put');

		$result = $this->Divan->update($this->Post, array('id', 'title'), array('uniquekey', 'A modified document'));
		$this->assertEqual($result->ok, 1);
		$this->assertEqual($result->id, 'uniquekey');

		$result = json_decode($this->Socket->get('/posts/uniquekey'));
		$this->assertEqual($result->_id, 'uniquekey');
		$this->assertEqual($result->title, 'A modified document');

		$this->Post->data = array('id' => 'uniquekey', 'title' => 'Yet another modification');
		$result = $this->Divan->update($this->Post);
		$this->assertEqual($result->ok, 1);
		$this->assertEqual($result->id, 'uniquekey');

		$result = json_decode($this->Socket->get('/posts/uniquekey'));
		$this->assertEqual($result->_id, 'uniquekey');
		$this->assertEqual($result->title, 'Yet another modification');
	}

	/**
	 * Tests the delete method of the Divan DataSource
	 *
	 * @return void
	 */
	function testDelete() {
		$this->Socket->put('/posts/uniquekey', json_encode(array('title' => 'this is a put')));
		$result = json_decode($this->Socket->get('/posts/uniquekey'));
		$this->assertEqual($result->_id, 'uniquekey');
		$this->assertEqual($result->title, 'this is a put');

		$result = $this->Divan->delete($this->Post, 'uniquekey');
		$this->assertEqual($result->ok, 1);
		$this->assertEqual($result->id, 'uniquekey');

		$result = json_decode($this->Socket->get('/posts/uniquekey'));
		$this->assertEqual($result->error, 'not_found');
		$this->assertEqual($result->reason, 'deleted');

		$this->Socket->put('/posts/uniquekey', json_encode(array('title' => 'this is a put')));
		$result = json_decode($this->Socket->get('/posts/uniquekey'));
		$this->assertEqual($result->_id, 'uniquekey');
		$this->assertEqual($result->title, 'this is a put');

		$this->Post->id = 'uniquekey';
		$result = $this->Divan->delete($this->Post);
		$this->assertEqual($result->ok, 1);
		$this->assertEqual($result->id, 'uniquekey');

		$result = json_decode($this->Socket->get('/posts/uniquekey'));
		$this->assertEqual($result->error, 'not_found');
		$this->assertEqual($result->reason, 'deleted');
	}

}

?>