<?php
/**
*
* @package phpBB Extension - RH Topic Tags
* @copyright © 2014 Robert Heim; significant overhauling © 2024 S. McCandlish (under same license).
* @license http://opensource.org/licenses/gpl-2.0.php GNU General Public License v2
*/
namespace robertheim\topictags\service;

/**
* Helper for executing database queries.
*/
class db_helper
{

	/**
	 * @var \phpbb\db\driver\driver_interface
	 */
	private $db;

	public function __construct(\phpbb\db\driver\driver_interface $db)
	{
		$this->db = $db;
	}

	/**
	 * Executes the SQL query and gets the results IDs.
	 *
	 * @param string $sql			an SQL query that fetches IDs
	 * @param string $field_name	the name of the field
	 * @return array int			array of IDs
	 */
	public function get_ids($sql, $field_name = 'id')
	{
		$result = $this->db->sql_query($sql);
		$ids = array();
		while ($row = $this->db->sql_fetchrow($result))
		{
			$ids[] = (int) $row[$field_name];
		}
		$this->db->sql_freeresult($result);
		return $ids;
	}

	/**
	 * Executes the given SQL and creates an array from the result using the
	 * $field_name column(s). When $field_name is just a string, the resulting
	 * array will be simple indexed; when $field_name is an array, this
	 * function's returned array will be a complex array (of arrays).
	 *
	 * This function appears to be "dead code"; no other files in the RH Topic
	 * Tags extension are calling this function.
	 *
	 * @param string $sql			the SQL string the result of which contains
	 *								 a column named $field_name
	 * @param string $field_name	the name of the field
	 * @return array				array of $field_name
	 */
	public function get_array_by_fieldname($sql, $field_name)
	{
		$result = $this->db->sql_query($sql);
		$re = array();

		while ($row = $this->db->sql_fetchrow($result))
		{
			// If $field_name is an array, use each and create a multiarray:
			if (is_array($field_name)) {
				$values = array();
				foreach ($field_name as $field) {
					$values[$field] = $row[$field];
				}
				$re[] = $values;
			} else {
				// Default case, when it's just a single field name:
				$re[] = $row[$field_name];
			}
		}

		$this->db->sql_freeresult($result);
		return $re;
	}	// Usage note: various portions of RH Topic Tags are expecting tags'
		// names in a simple array to operate immediately on them as strings.
		// So the average function calling THIS function needs to flatten
		// any complex array it returns for further processing, e.g. with:
		// $tagNames = array_map(function($tag) { return $tag['tag']; }, $tagsList);

	/**
	 * Executes the SQL and fetches the rows as array.
	 *
	 * @param string $sql	the SQL string
	 * @param int $limit	optional limit
	 * @param int $start	optional start
	 * @return array		the resulting array
	 */
	public function get_array($sql, $limit = 0, $start = 0)
	{
		if ($limit > 0) {
			$result = $this->db->sql_query_limit($sql, $limit, $start);
		} else {
			$result = $this->db->sql_query($sql);
		}
		$re = array();
		while ($row = $this->db->sql_fetchrow($result))
		{
			$re[] = $row;
		}
		$this->db->sql_freeresult($result);
		return $re;
	}

	/**
	 * Executes the given SQL and creates a multiarray of arrays from the
	 * result, using the $field_names columns.
	 *
	 * @param string $sql			the SQL string the result of which contains
	 *								 a column named $field_name
	 * @param array $field_names	the name of the columns to use
	 * @param int $limit			optional limit
	 * @param int $start			optional start
	 * @return array				array of arrays, e.g.:
	 *								 [['a'=> ..., 'b' => ...], [...], ...]]
	 */
	public function get_multiarray_by_fieldnames($sql, array $field_names, $limit = 0, $start = 0)
	{
		if ($limit > 0) {
			$result = $this->db->sql_query_limit($sql, $limit, $start);
		} else {
			$result = $this->db->sql_query($sql);
		}
		$re = array();
		while ($row = $this->db->sql_fetchrow($result))
		{
			$data = array();
			foreach ($field_names as $field_name)
			{
				$data[$field_name] = $row[$field_name];
			}
			$re[] = $data;
		}
		$this->db->sql_freeresult($result);
		return $re;
	}

	/**
	 * Executes the given $sql and fetches the field $field_name
	 *
	 * @param string $sql			the SQL query
	 * @param string $field_name	the name of the field to fetch
	 * @param int $limit			optional limit
	 * @param int $start			optional start
	 * @return						the value of the field
	 */
	public function get_field($sql, $field_name, $limit = 0, $start = 0)
	{
		if ($limit > 0) {
			$result = $this->db->sql_query_limit($sql, $limit, $start);
		} else {
			$result = $this->db->sql_query($sql);
		}
		$re = $this->db->sql_fetchfield($field_name);
		$this->db->sql_freeresult($result);
		return $re;
	}
}
