<?php
declare(ENCODING = 'utf-8');
namespace F3\CouchDB;

/*                                                                        *
 * This script belongs to the FLOW3 package "CouchDB".                    *
 *                                                                        *
 * It is free software; you can redistribute it and/or modify it under    *
 * the terms of the GNU Lesser General Public License as published by the *
 * Free Software Foundation, either version 3 of the License, or (at your *
 * option) any later version.                                             *
 *                                                                        *
 * This script is distributed in the hope that it will be useful, but     *
 * WITHOUT ANY WARRANTY; without even the implied warranty of MERCHAN-    *
 * TABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the GNU Lesser       *
 * General Public License for more details.                               *
 *                                                                        *
 * You should have received a copy of the GNU Lesser General Public       *
 * License along with the script.                                         *
 * If not, see http://www.gnu.org/licenses/lgpl.html                      *
 *                                                                        *
 * The TYPO3 project - inspiring people to share!                         *
 *                                                                        */

/**
 * A CouchDB client
 *
 * @license http://www.gnu.org/licenses/lgpl.html GNU Lesser General Public License, version 3 or later
 * @scope prototype
 */
class Client {

	/**
	 * @inject
	 * @var \F3\FLOW3\Object\ObjectManagerInterface
	 */
	protected $objectManager;

	/**
	 *
	 * @var \F3\CouchDB\Client\HttpConnector
	 */
	protected $connector;

	/**
	 * @var string
	 */
	protected $databaseName;

	/**
	 * Create a new CouchDB client
	 *
	 * @param string $dataSourceName The CouchDB connection parameters as URL, e.g. http://user:pass@127.0.0.1:5984
	 * @param array $options Additional connection options for the HttpConnector
	 * @author Christopher Hlubek <hlubek@networkteam.com>
	 * @author Karsten Dambekalns <karsten@typo3.org>
	 */
	public function __construct($dataSourceName, array $options = array()) {
		$this->dataSourceName = $dataSourceName;
		$this->options = $options;
	}

	/**
	 * @return void
	 * @author Karsten Dambekalns <karsten@typo3.org>
	 */
	public function initializeObject() {
		if (($urlParts = parse_url($this->dataSourceName)) === FALSE) {
			throw new \InvalidArgumentException('Invalid data source name: ' . $this->dataSourceName, 1287346792);
		}
		$host = isset($urlParts['host']) ? $urlParts['host'] : NULL;
		$port = isset($urlParts['port']) ? $urlParts['port'] : NULL;
		$username = isset($urlParts['user']) ? $urlParts['user'] : NULL;
		$password = isset($urlParts['pass']) ? $urlParts['pass'] : NULL;
		$this->connector = $this->objectManager->create('F3\CouchDB\Client\HttpConnector', $host, $port, $username, $password, $this->options);
	}

	/**
	 * List all databases
	 *
	 * @return array
	 * @author Christopher Hlubek <hlubek@networkteam.com>
	 */
	public function listDatabases() {
		return $this->connector->get('/_all_dbs');
	}

	/**
	 * Create a database
	 *
	 * @param string $databaseName The database name
	 * @return boolean
	 * @author Christopher Hlubek <hlubek@networkteam.com>
	 */
	public function createDatabase($databaseName) {
		$response = $this->connector->put('/' . urlencode($databaseName));
		return $response instanceof \F3\CouchDB\Client\StatusResponse && $response->isSuccess();
	}

	/**
	 * Delete a database
	 *
	 * @param string $databaseName The database name
	 * @return boolean
	 * @author Christopher Hlubek <hlubek@networkteam.com>
	 */
	public function deleteDatabase($databaseName) {
		$response = $this->connector->delete('/' . urlencode($databaseName));
		return $response instanceof \F3\CouchDB\Client\StatusResponse && $response->isSuccess();
	}

	/**
	 * Get information about a database
	 *
	 * @param string $databaseName The database name
	 * @return object
	 * @author Christopher Hlubek <hlubek@networkteam.com>
	 */
	public function databaseInformation($databaseName) {
		return $this->connector->get('/' . urlencode($databaseName));
	}

	/**
	 * Check if a database exists
	 *
	 * @param string $databaseName The database name
	 * @return boolean TRUE if the database exists
	 * @author Christopher Hlubek <hlubek@networkteam.com>
	 */
	public function databaseExists($databaseName) {
		try {
			$information = $this->databaseInformation($databaseName);
			return is_object($information) && $information->db_name === $databaseName;
		} catch(\F3\CouchDB\Client\NotFoundException $e) {
			return FALSE;
		}
	}

	/**
	 * Get all documents in the database
	 *
	 * @param array $query Additional query options (e.g. limit or include_docs)
	 * @return object
	 * @author Christopher Hlubek <hlubek@networkteam.com>
	 */
	public function listDocuments(array $query = NULL) {
		return $this->connector->get('/' . urlencode($this->getDatabaseName()) . '/_all_docs', $query);
	}

	/**
	 * Get a single document by id
	 *
	 * @param string id The document id
	 * @param array $query Additional query options (e.g. revs or rev)
	 * @return object
	 * @author Christopher Hlubek <hlubek@networkteam.com>
	 */
	public function getDocument($id, array $query = NULL) {
		return $this->connector->get('/' . urlencode($this->getDatabaseName()) . '/' . $this->encodeId($id), $query);
	}

	/**
	 * Get multiple documents by id.
	 *
	 * Use include_docs => TRUE as query option to fetch the documents.
	 *
	 * @param array $ids The document ids as array
	 * @param array $query Additional query options (e.g. include_docs)
	 * @return object
	 * @author Christopher Hlubek <hlubek@networkteam.com>
	 */
	public function getDocuments(array $ids, array $query = NULL) {
		return $this->connector->post('/' . urlencode($this->getDatabaseName()) . '/_all_docs', $query, json_encode(array('keys' => $ids)));
	}

	/**
	 * Create a document either with a specified id, or by assigning a UUID
	 * through CouchDB.
	 *
	 * @param mixed $document The document as a string, array or object
	 * @param mixed $id An optional id to use for the document
	 * @return \F3\CouchDB\Client\StatusResponse The creation response
	 * @author Christopher Hlubek <hlubek@networkteam.com>
	 * @author Karsten Dambekalns <karsten@typo3.org>
	 */
	public function createDocument($document, $id = NULL) {
		if (!is_string($document)) {
			$document = json_encode($document);
		}
		if ($id === NULL) {
			return $this->connector->post('/' . urlencode($this->getDatabaseName()), NULL, $document);
		} else {
			return $this->connector->put('/' . urlencode($this->getDatabaseName()) . '/' . $this->encodeId($id), NULL, $document);
		}
	}

	/**
	 * Update a document
	 *
	 * @param mixed $document The document as a string, array or object
	 * @param string $id The document id
	 * @return \F3\CouchDB\Client\StatusResponse The update response
	 * @author Christopher Hlubek <hlubek@networkteam.com>
	 * @author Karsten Dambekalns <karsten@typo3.org>
	 */
	public function updateDocument($document, $id) {
		if (!is_string($document)) {
			$document = json_encode($document);
		}
		return $this->connector->put('/' . urlencode($this->getDatabaseName()) . '/' . $this->encodeId($id), NULL, $document);
	}

	/**
	 * Delete a document
	 *
	 * @param string $id The document id
	 * @param string $revision The document revision
	 * @return boolean TRUE if the deletion was successful
	 * @author Christopher Hlubek <hlubek@networkteam.com>
	 */
	public function deleteDocument($id, $revision) {
		$response = $this->connector->delete('/' . urlencode($this->getDatabaseName()) . '/' . $this->encodeId($id), array('rev' => $revision));
		return is_object($response) && $response->ok === TRUE;
	}

	/**
	 * Query a view
	 *
	 * In addition to the default view query options (key, startkey, endkey, ...)
	 * the query parameter "keys" can be specified to do multi-key lookups.
	 *
	 * @param string $designDocumentName The design document name
	 * @param string $viewName The view name
	 * @param array $query Query options
	 * @return mixed
	 * @author Christopher Hlubek <hlubek@networkteam.com>
	 */
	public function queryView($designDocumentName, $viewName, array $query = NULL) {
		$path = '/' . urlencode($this->getDatabaseName()) . '/_design/' . urlencode($designDocumentName) . '/_view/' . urlencode($viewName);
		if ($query === NULL || !isset($query['keys'])) {
			return $this->connector->get($path, $query);
		} else {
			$keys = $query['keys'];
			unset($query['keys']);
			return $this->connector->post($path, $query, json_encode(array('keys' => $keys)));
		}
	}

	/**
	 * Encode a document id and preserve slashes for design documents
	 *
	 * @param string $id
	 * @return string
	 * @author Christopher Hlubek <hlubek@networkteam.com>
	 */
	protected function encodeId($id) {
		if (strpos($id, '_design/') === 0) {
			return '_design/' . urlencode(substr($id, strlen('_design/')));
		} else {
			return urlencode($id);
		}
	}

	/**
	 * @return \F3\CouchDB\Client\HttpConnector
	 * @author Christopher Hlubek <hlubek@networkteam.com>
	 */
	public function getConnector() {
		return $this->connector;
	}

	/**
	 * @return string
	 * @author Christopher Hlubek <hlubek@networkteam.com>
	 */
	protected function getDatabaseName() {
		if ($this->databaseName === NULL) {
			throw new \F3\FLOW3\Persistence\Exception('No database name set', 1287349160);
		}
		return $this->databaseName;
	}

	/**
	 * @param string $databaseName
	 * @return void
	 * @author Christopher Hlubek <hlubek@networkteam.com>
	 */
	public function setDatabaseName($databaseName) {
		$this->databaseName = $databaseName;
	}
}

?>