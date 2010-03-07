<?php defined('SYSPATH') or die('No direct script access.');
/**
 * YQL_Result contains a single result record from a YQL query, usually
 * created by the YQL_Iterator
 *
 * @package  YQL
 * @author   Sam Clark
 * @version  $Id: YQL_Result.php 9 2009-05-14 10:24:53Z samsoir $
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
class YQL_Result_Core {

	/**
	 * The result object
	 *
	 * @var   array
	 */
	protected $object;

	/**
	 * Constructor, just assigns the data array to the object
	 *
	 * @param   array        data 
	 * @author  Sam Clark
	 * @access  public
	 */
	public function __construct(array $data)
	{
		$this->object = $data;
	}

	/**
	 * Magic method to access properties of this object
	 *
	 * @param   string       key
	 * @return  mixed
	 * @author  Sam Clark
	 * @access  public
	 */
	public function __get($key)
	{
		// Return the index of the object
		return $this->object[$key];
	}

	/**
	 * Return this YQL_Result as an array.
	 * Using the __get() method to ensure any
	 * transformations (from extensions) are
	 * processed before output
	 *
	 * @return  array
	 * @author  Sam Clark
	 */
	public function as_array()
	{
		// Array
		$array = array();

		// Foreach key/value pair in the array
		foreach ($this->object as $key => $value)
			$array[$key] = $this->$value;

		return $array;
	}
}
