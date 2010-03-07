<?php defined('SYSPATH') or die('No direct script access.');
/**
 * YQL core library including query builder and result objects
 * 
 * Yahoo! makes a lot of structured data available to developers, 
 * primarily through its web services. These services require developers 
 * to locate the right URLs and documentation to access and query them 
 * which can result in a very fragmented experience. The YQL platform 
 * provides a single endpoint service that enables developers to query, 
 * filter and combine data across Yahoo! and beyond. YQL exposes a 
 * SQL-like SELECT syntax that is both familiar to developers and 
 * expressive enough for getting the right data. Through the SHOW and 
 * DESC commands we enable developers to discover the available data 
 * sources and structure without opening another web browser.
 * 
 * More information available at : http://developer.yahoo.com/yql/
 * 
 * This library works similar to the Database library query builder,
 * allowing you to construct YQL queries within your code, execute them
 * and get an iterator result object
 *
 * @package  YQL
 * @author   Sam Clark
 * @version  $Id: YQL.php 11 2009-05-15 11:25:26Z samsoir $
 * @license  ISC, http://www.opensource.org/licenses/isc-license.txt
 * 
 * THE SOFTWARE IS PROVIDED "AS IS" AND THE AUTHOR DISCLAIMS ALL WARRANTIES 
 * WITH REGARD TO THIS SOFTWARE INCLUDING ALL IMPLIED WARRANTIES OF 
 * MERCHANTABILITY AND FITNESS. IN NO EVENT SHALL THE AUTHOR BE LIABLE FOR 
 * ANY SPECIAL, DIRECT, INDIRECT, OR CONSEQUENTIAL DAMAGES OR ANY DAMAGES 
 * WHATSOEVER RESULTING FROM LOSS OF USE, DATA OR PROFITS, WHETHER IN AN 
 * ACTION OF CONTRACT, NEGLIGENCE OR OTHER TORTIOUS ACTION, ARISING OUT OF 
 * OR IN CONNECTION WITH THE USE OR PERFORMANCE OF THIS SOFTWARE.
 */
class YQL_Core {

	/**
	 * Plugin version number
	 */
	const VERSION = 0.1;

	/**
	 * API points of access
	 */
	const YQL_PRIVATE_API = 'http://query.yahooapis.com/v1/yql?q=';
	const YQL_PUBLIC_API = 'http://query.yahooapis.com/v1/public/yql?q=';

	/**
	 * Configuration for YQL
	 *
	 * @var   array
	 */
	protected $config;

	/**
	 * The cURL library used for comms
	 *
	 * @var   Curl
	 */
	protected $curl;

	/**
	 * Cache library
	 *
	 * @var   Cache
	 */
	protected $cache;

	/**
	 * The diagnostics returned from YQL
	 *
	 * @var   array
	 */
	protected $diagnostics;

	/**
	 * Select clauses
	 *
	 * @var   array
	 */
	protected $select = array();

	/**
	 * From clauses (this should be singular)
	 *
	 * @var   string
	 */
	protected $from;

	/**
	 * Where clauses
	 *
	 * @var   array
	 */
	protected $where = array();

	/**
	 * Limit clauses
	 *
	 * @var   array
	 */
	protected $limit = array();

	/**
	 * The rendered query to be sent to YQL
	 *
	 * @var   string
	 */
	protected $query;

	/**
	 * Factory method for creating new YQL objects
	 *
	 * @param   array        config 
	 * @return  YQL
	 * @author  Sam Clark
	 * @access  public
	 * @static
	 */
	public static function factory(array $config = array())
	{
		return new YQL($config);
	}

	/**
	 * This method will determine whether it is still compatible
	 * with the version request.
	 * 
	 * I.e. This library is 0.4, and you request 0.1 - if 0.4 is
	 * compatible with 0.1 then it will return TRUE
	 *
	 * @param   mixed $version 
	 * @return  bool
	 * @author  Sam Clark
	 */
	public static function version($version)
	{
		return ($version <= self::VERSION);
	}

	/**
	 * Constructor to setup this model, apply the configuration,
	 * setup Curl and add Events
	 *
	 * @param   array        config
	 * @return  void
	 * @author  Sam Clark
	 * @access  public
	 */
	public function __construct(array $config = array())
	{
		// Setup the configuration
		$config += Kohana::config('yql');
		$this->config = $config;

		// Load the cache library if required
		if ($this->config['cache'])
			$this->cache = Cache::instance();

		// Load the curl library
		$this->curl = Curl::factory(array(CURLOPT_POST => FALSE));

		// Setup the reload event to ensure this library is
		// ready to be used again
		Event::add('yql.post_execute', array($this, 'clear_queries'));
		Event::add('yql.post_execute', array($this, 'reload_curl'));

		return;
	}

	/**
	 * Retrieves a list of Yahoo! supported tables
	 *
	 * @return  YQL_Iterator
	 * @author  Sam Clark
	 * @access  public
	 */
	public function show()
	{
		// Clear existing queries
		$this->clear_queries();
		
		// Set the select statement
		$this->select = array('SHOW' => 'tables');

		// Execute the query
		return $this->exec();
	}

	/**
	 * Retrieves information about predefinied Yahoo! tables. This
	 * will not work for non-Yahoo! or non-Community tables, such
	 * as personal blogs or other unaffiliated sites.
	 *
	 * @param   string       table
	 * @return  YQL_Result
	 * @author  Sam Clark
	 * @access  public
	 */
	public function desc($table)
	{
		// Clear existing queries
		$this->clear_queries();

		// If the url is valid
		if (valid::url($table))
			throw new Kohana_User_Exception('YQL::desc()', 'DESC only allows use of Yahoo! pre-registered tables. See http://developer.yahoo.com/yql/ for more information.');

		// Set the desc statement
		$this->select = array('DESC' => $table);

		// Execute the query
		return $this->exec();
	}

	/**
	 * Select method used to create the SELECT statement
	 *
	 * @param   string       select 
	 * @param   array        select
	 * @return  self
	 * @author  Sam Clark
	 * @access  public
	 */
	public function select($select = '*')
	{
		// If $select is a string, add it to the array
		if ( ! is_array($select))
			$this->select[] = $select;
		// Otherwise merge the current array
		else
			$this->select += $select;

		// Return this
		return $this;
	}

	/**
	 * From clause used to form the FROM statement
	 *
	 * @param   string       from 
	 * @return  self
	 * @author  Sam Clark
	 * @access  public
	 */
	public function from($from)
	{
		// Type cast $from to string
		$this->from = (string) $from;

		// Return this
		return $this;
	}

	/**
	 * undocumented function
	 *
	 * @param   string       key 
	 * @param   string       value
	 * @param   boolean      quote  whether to quote the variables or not
	 * @param   string       mode
	 * @return  self
	 * @author  Sam Clark
	 * @access  public
	 * @throws  Kohana_User_Exception
	 */
	public function where($key, $value = NULL, $quote = TRUE, $mode = 'AND')
	{
		// Get the number of arguments for sanity checking
		$num_args = func_num_args();

		// If $value is NULL, $key should be an array
		if ($num_args == 1 AND ! is_array($key))
			throw new Kohana_User_Exception('YQL::where()', 'value argument must be set if key is not an array');

		// Format the key ready for insertion
		if ( ! is_array($key))
			$key = array($key, $value);

		foreach ($key as $k => $v)
			$this->where[] = $this->add_where($k, $v, $mode, $quote);

		return $this;
	}

	/**
	 * Performs and OR WHERE statements
	 *
	 * @param   string       key
	 * @param   string       value
	 * @param   string       quote
	 * @return  self
	 * @author  Sam Clark
	 * @access  public
	 */
	public function orwhere($key, $value = NULL, $quote = TRUE)
	{
		// Use where, but change mode to OR
		$this->where($key, $value, $quote, 'OR');

		return $this;
	}

	/**
	 * Creates the IN clause
	 *
	 * @param   string       key 
	 * @param   string       values 
	 * @return  self
	 * @author  Sam Clark
	 * @access  public
	 */
	public function in($key, $values)
	{
		// Inspect the values type and format appropriately
		if ($values instanceof YQL_Core)
			$this->where($key, (string) $values, -1);
		elseif (is_array($values))
			$this->where($key, implode(',', $values), -1);
		else
			$this->where($key, (string) $values, -1);

		return $this;
	}

	/**
	 * Creates a like statement, takes either a key string
	 * with value pair, or an array of key/values
	 *
	 * @param   string       field 
	 * @param   array        field 
	 * @param   mixed        value 
	 * @param   string       type 
	 * @return  self
	 * @author  Sam Clark
	 * @access  public
	 */
	public function like($field, $value = '', $type = 'AND')
	{
		// Figure out whether the prefix is required
		$prefix = count($this->where) ? $type.' ' : '';

		// If field is an array
		if (is_array($field))
		{
			// Process each statement
			foreach ($field as $key => $val)
				$this->like($key, $val, $type);
		}
		// Otherwise, add the like statement to the where clause
		else
			$this->where[] = $prefix.$field.' LIKE '.$this->escape($value);

		return $this;
	}

	/**
	 * Create an OR LIKE statement, using self::like() method
	 *
	 * @param   string       field 
	 * @param   array        field 
	 * @param   mixed        value 
	 * @return  self
	 * @author  Sam Clark
	 * @access  public
	 */
	public function orlike($field, $value = '')
	{
		$this->like($field, $value, 'OR');
		return $this;
	}

	/**
	 * Provides limit/offset controls, for pagination
	 *
	 * @param   int          limit 
	 * @param   int          offset 
	 * @return  self
	 * @author  Sam Clark
	 * @access  public
	 */
	public function limit($limit, $offset = NULL)
	{
		// If is an array, apply to limit
		if (is_array($limit))
			$this->limit = $limit;
		// Otherwise place the limit and offset in the limit array
		else
			$this->limit = array('limit' => $limit, 'offset' => $offset);

		return $this;
	}

	/**
	 * Executes a YQL query, either the stored query or
	 * the string supplied
	 *
	 * @param   string       statement to query [Optional]
	 * @return  YQL_Iterator
	 * @author  Sam Clark
	 * @access  public
	 */
	public function query($statement = FALSE)
	{
		if (is_string($statement))
			$this->query = $this->render_query($statement);

		// Run the query and return the YQL_Iterator object
		return $this->exec();
	}

	/**
	 * Renders this YQL object to a string
	 *
	 * @return  string
	 * @author  Sam Clark
	 * @access  public
	 */
	public function __toString()
	{
		// Return just the YQL of this object
		return $this->render_query();
	}

	/**
	 * Renders the query into clean YQL format
	 *
	 * @param   string       query  a single YQL statement to pass straight to the API
	 * @return  string
	 * @author  Sam Clark
	 * @access  protected
	 */
	protected function render_query($query = FALSE)
	{
		// If there has been a query supplied
		if ($query)
		{
			// Assign the query to the library
			$this->query = $query;

			return $this->query;
		}

		// Setup the empty statement
		$statement = '';

		/* SELECT */

		// If this is a SHOW or DESC query
		if (array_key_exists('SHOW', $this->select) OR array_key_exists('DESC', $this->select))
		{
			// Take the first key and value
			$statement = key($this->select).' '.current($this->select);

			// Apply the statement to the query property
			$this->query = $statement;

			// Return the correctly formatted version, depending on $yql_exclusive
			return $this->query;
		}
		else
			// Otherwise create the select statements
			$statement = 'SELECT '.implode(',', $this->select).' ';

		/* FROM */

		if ($this->from === NULL)
			throw new Kohana_User_Exception('YQL::render_query()', 'There must be a FROM statement in the YQL query');

		// Set the FROM status
		$statement .= 'FROM '.$this->from.' ';

		/* WHERE */
		if ($this->where)
		{
			// Open WHERE statement
			$statement .= 'WHERE ';

			// Foreach where clause
			foreach ($this->where as $where)
				$statement .= $where.' ';
		}

		/* LIMIT / OFFSET */
		if ($this->limit)
		{
			// Get the limit and offset
			extract($this->limit);

			// L
			if ($limit)
				$statement .= 'LIMIT '.$limit.' ';

			if ($offset)
				$statement .= 'OFFSET '.$offset;
		}

		// Apply statement to this query
		$this->query = $statement;

		// Return the statement
		return $this->query;
	}

	/**
	 * Executes the query and returns 
	 *
	 * @return  YQL_Iterator
	 * @return  YQL_Result
	 * @author  Sam Clark
	 * @access  protected
	 */
	protected function exec()
	{
		// If the query hasn't been rendered, render it
		if ($this->query === NULL)
			$this->render_query();

		// Execute the query
		$data = $this->curl
			->setopt(CURLOPT_URL, $this->format_query($this->query))
			->exec()
			->result();

		// Run the post execute
		Event::run('yql.post_execute');

		return $this->parse($data);
	}

	/**
	 * Formats the query ready for transmission
	 *
	 * @param   string       query 
	 * @return  string
	 * @author  Sam Clark
	 * @access  protected
	 */
	protected function format_query($query)
	{
		$formatted_query = $this->config['api'].rawurlencode($query).'&format=json';

		if ( ! $this->config['diagnostics'])
			$formatted_query .= '&diagnostics=false';

		return $formatted_query;
	}

	/**
	 * Clears the queries and resets the library
	 *
	 * @return  void
	 * @author  Sam Clark
	 * @access  public
	 */
	public function clear_queries()
	{
		// Setup the properties to clear
		$properties = array('select', 'where', 'from', 'limit');

		// Reset the properties
		foreach ($properties as $property)
			$this->$property = array();

		// Clear the query string
		$this->query = NULL;

		return;
	}

	/**
	 * Reloads the cURL library after execution
	 *
	 * @return  void
	 * @author  Sam Clark
	 * @access  public
	 */
	public function reload_curl()
	{
		// If there is no cURL library or cURL has executed
		if ($this->curl === NULL OR $this->curl->executed)
			$this->curl = Curl::factory(array(CURLOPT_POST => FALSE));

		return;
	}

	/**
	 * Parses the response data and returns the appropriate YQL Result or Iterator
	 *
	 * @param   string         data 
	 * @return  YQL_Iterator
	 * @return  YQL_Result
	 * @author  Sam Clark
	 * @access  protected
	 * @throws  Kohana_User_Exception
	 */
	protected function parse($data)
	{
		if ( ! is_string($data))
			throw new Kohana_User_Exception('YQL::parse()', 'data to parse is not a string!');

		if (strpos('text/xml', $this->curl->header('Content-Type')) !== FALSE)
			throw new Kohana_User_Exception('YQL::parse()', 'XML is not currently supported!');

		// Decode the result, and get the query array
		$result = json_decode($data, TRUE);

		// Detect errors and throw exception if present
		if (array_key_exists('error', $result))
			throw new Kohana_User_Exception('YQL::parse()', $data['error']['description']);

		// Set the diagnostics, if available
		if ($this->config['diagnostics'])
			$this->diagnostics = $result['query']['diagnostics'];

		// Set the result to the query response
		$result = $result['query'];

		if ($result['results'] === NULL)
			return NULL;

		// If the result is a single table, return 
		if (array_key_exists('table', $result['results']))
		{
			// Parse the result if it is a DESC request
			$keys = array_pop($result['results']['table']);
			$result = $result['results']['table'];
			$result['keys'] = $keys['select']['key'];
			
			return new YQL_Result($result);
		}

		if (array_key_exists('item', $result['results']))
			return new YQL_Iterator($result['results']['item']);

		// Try and parse the YQL result into something meaningful
		if (count($result['results']) == 1 AND is_string($key = key($result['results'])))
			$result['results'] = $result['results'][$key];

		return new YQL_Iterator($result['results']);
	}

	/**
	 * Formats the supplied where clause
	 *
	 * @param   string       key 
	 * @param   string       value 
	 * @param   string       type 
	 * @param   string       quote 
	 * @return  string
	 * @author  Sam Clark
	 * @access  protected
	 */
	protected function add_where($key, $value, $type, $quote)
	{
		// Figure out whether the prefix is required
		$prefix = count($this->where) ? $type.' ' : '';

		// Sanitise values
		if ($value === NULL)
		{
			// If the value is NULL and there is no operator, default to IS
			if ( ! $this->has_operator($key))
				$key .= ' IS';

			// Replace value with YQL NULL
			$value = ' NULL';
		}
		elseif (is_bool($value))
		{
			// If the value is boolean and there is no operator, default to equals
			if ( ! $this->has_operator($key))
				$key .= ' =';

			// Convert boolean values to YQL types
			$value = $value ? '1' : '0';
		}
		else
		{
			// If the key has no operator, default to equals
			if ( ! $this->has_operator($key))
				$key .= ' =';

			switch ($quote)
			{
				case TRUE :
					$value = $this->escape($value);
					break;
				case -1 :
					$value = $this->parentheses($value);
					break;
			}
		}

		// Add this where statement to the where array
		return $prefix.$key.$value;
	}

	/**
	 * Escapes a value with correct formatting
	 *
	 * @param   string       value 
	 * @return  string
	 * @author  Sam Clark
	 */
	protected function escape($value)
	{
		$type = gettype($value);

		switch ($type)
		{
			case 'string' :
				$value = '\''.$value.'\'';
				break;
			case 'boolean' :
				$value = (int) $value;
				break;
			case 'double' :
				$value = sprintf('%F', $value);
				break;
			default :
				$value = ($value === NULL) ? 'NULL' : $value;
		}
		return $value;
	}

	/**
	 * Places the supplied value in parentheses
	 *
	 * @param   string       value 
	 * @return  string
	 * @author  Sam Clark
	 */
	protected function parentheses($value)
	{
		return '('.$value.')';
	}

	/**
	 * Determines if the string has an arithmetic operator in it.
	 *
	 * @param   string       str  string to check
	 * @return  boolean
	 * @author  Woody Gilk
	 * @access  protected
	 */
	protected function has_operator($str)
	{
		return (bool) preg_match('/[<>!=]|\sIS(?:\s+NOT\s+)?\b/i', trim($str));
	}

} // End YQL_Core