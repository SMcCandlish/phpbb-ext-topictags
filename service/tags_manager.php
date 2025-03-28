<?php
/**
*
* @package phpBB Extension - RH Topic Tags
* @copyright © 2014 Robert Heim; significant overhauling and new functions © 2025 S. McCandlish (under same license).
* @license http://opensource.org/licenses/gpl-2.0.php GNU General Public License v2
*
*/

namespace robertheim\topictags\service;

/**
 * @ignore
 */
use robertheim\topictags\tables;
use robertheim\topictags\prefixes;
use robertheim\topictags\service\db_helper;

/**
* Handles all functionallity regarding tags.
* This class is basically a manager (functions for cleaning and validating tags)
* and a DAO (storing tags to and retrieving them from the database).
*/
class tags_manager
{
	/** @var \phpbb\db\driver\driver_interface */
	private $db;

	/** @var \phpbb\config\config */
	private $config;

	/** @var \phpbb\config\db_text */
	private $config_text;

	/** @var \phpbb\auth\auth */
	private $auth;

	/** @var \phpbb\language\language */
	private $language;

	/** @var \phpbb\user */
	private $user;

	/** @var db_helper */
	private $db_helper;

	/** @var string */
	private $table_prefix;
	
	public function __construct(
		\phpbb\db\driver\driver_interface $db,
		\phpbb\config\config $config,
		\phpbb\config\db_text $config_text,
		\phpbb\auth\auth $auth,
		\phpbb\language\language $language,
		\phpbb\user $user,
		db_helper $db_helper,
		$table_prefix
	)
	{
		$this->db			= $db;
		$this->config		= $config;
		$this->config_text	= $config_text;
		$this->auth			= $auth;
		$this->language		= $language;
		$this->user			= $user;
		$this->db_helper	= $db_helper;
		$this->table_prefix	= $table_prefix;
	}

	/**
	 * Remove all tags from the given (single) topic.
	 *
	 * @param $topic_id				topic ID
	 * @param $delete_unused_tags 	If set to true, unused tags are removed from the db.
	 */
	public function remove_all_tags_from_topic($topic_id, $delete_unused_tags = true)
	{
		$this->remove_all_tags_from_topics(array($topic_id), $delete_unused_tags);
	}

	/**
	 * Remove tag assignments from the given (multiple) topics.
	 *
	 * @param $topic_ids			array of topic IDs
	 * @param $delete_unused_tags	If set to true, unused tags are removed from the db.
	 */
	public function remove_all_tags_from_topics(array $topic_ids, $delete_unused_tags = true)
	{
		// Remove tags from topic:
		$sql = 'DELETE FROM ' . $this->table_prefix . tables::TOPICTAGS. '
			WHERE ' . $this->db->sql_in_set('topic_id', $topic_ids);
		$this->db->sql_query($sql);
		if ($delete_unused_tags) {
			$this->delete_unused_tags();
		}
		$this->calc_count_tags();
	}

	/**
	 * Gets the IDs of all tags that are not assigned to a topic.
	 */
	private function get_unused_tag_ids()
	{
		$sql = 'SELECT t.id
			FROM ' . $this->table_prefix . tables::TAGS . ' t
			WHERE NOT EXISTS (
				SELECT 1
				FROM ' . $this->table_prefix . tables::TOPICTAGS . ' tt
					WHERE tt.tag_id = t.id
			)';
		return $this->db_helper->get_ids($sql);
	}

	/**
	 * Removes all tags that are not assigned to at least one topic (garbage
	 * collection).
	 *
	 * @return integer	count of deleted tags
	 */
	public function delete_unused_tags()
	{
		$ids = $this->get_unused_tag_ids();
		if (empty($ids)) {
			// nothing to do
			return 0;
		}
		$sql = 'DELETE FROM ' . $this->table_prefix . tables::TAGS . '
			WHERE ' . $this->db->sql_in_set('id', $ids);
		$this->db->sql_query($sql);
		return $this->db->sql_affectedrows();
	}

	/**
	 * Deletes all assignments of tags that are no longer valid.
	 *
	 * @return integer	count of removed assignments
	 */
	public function delete_assignments_of_invalid_tags()
	{
		// Get all tags to check them:
		$tags = $this->get_existing_tags(null);

		$ids_of_invalid_tags = array();
		foreach ($tags as $tag) {
			if (!$this->is_valid_tag($tag['tag'])) {
				$ids_of_invalid_tags[] = (int) $tag['id'];
			}
		}
		if (empty($ids_of_invalid_tags)) {
			// Nothing to do.
			return 0;
		}

		// Delete all tag-assignments where the tag is not valid:
		$sql = 'DELETE FROM ' . $this->table_prefix . tables::TOPICTAGS . '
			WHERE ' . $this->db->sql_in_set('tag_id', $ids_of_invalid_tags);
		$this->db->sql_query($sql);
		$removed_count = $this->db->sql_affectedrows();

		$this->calc_count_tags();

		return $removed_count;
	}

	/**
	 * Identifies all tag-assignments where the topic does not exist anymore.
	 *
	 * @return array	array of "dead" tag-assignments
	 */
	private function get_assignment_ids_where_topic_does_not_exist()
	{
		$sql = 'SELECT tt.id
			FROM ' . $this->table_prefix . tables::TOPICTAGS . ' tt
			WHERE NOT EXISTS (
				SELECT 1
				FROM ' . TOPICS_TABLE . ' topics
					WHERE topics.topic_id = tt.topic_id
			)';
		return $this->db_helper->get_ids($sql);
	}

	/**
	 * Removes all tag-assignments where the topic does not exist anymore.
	 *
	 * @return integer	count of deleted assignments
	 */
	public function delete_assignments_where_topic_does_not_exist()
	{
		$ids = $this->get_assignment_ids_where_topic_does_not_exist();
		if (empty($ids)) {
			// nothing to do
			return 0;
		}
		// Delete all tag-assignments where the topic does not exist anymore:
		$sql = 'DELETE FROM ' . $this->table_prefix . tables::TOPICTAGS . '
			WHERE ' . $this->db->sql_in_set('id', $ids);
		$this->db->sql_query($sql);
		$removed_count = $this->db->sql_affectedrows();

		$this->calc_count_tags();

		return $removed_count;
	}

	/**
	 * Deletes all tag-assignments where the topic resides in a forum with
	 * tagging disabled.
	 *
	 * @param $forum_ids	array of forum-ids that should be checked (if null,
	 *						 all are checked)
	 * @return integer		count of deleted assignments
	 */
	public function delete_tags_from_tagdisabled_forums($forum_ids = null)
	{
		$forums_sql_where = '';

		if (is_array($forum_ids)) {
			if (empty($forum_ids)) {
				// Performance improvement, because we already know the result
				// of querying the db.
				return 0;
			}
			$forums_sql_where = ' AND ' . $this->db->sql_in_set('f.forum_id', $forum_ids);
		}

		// Get IDs of all tag-assignments to topics that reside in a forum with
		// tagging disabled:
		$sql = 'SELECT tt.id
			FROM ' . $this->table_prefix . tables::TOPICTAGS . ' tt
			WHERE EXISTS (
				SELECT 1
				FROM ' . TOPICS_TABLE . ' topics,
					' . FORUMS_TABLE . " f
				WHERE topics.topic_id = tt.topic_id
					AND f.forum_id = topics.forum_id
					AND f.rh_topictags_enabled = 0
					$forums_sql_where
			)";
		$delete_ids = $this->db_helper->get_ids($sql);

		if (empty($delete_ids)) {
			// Nothing to do.
			return 0;
		}
		// Delete these assignments:
		$sql = 'DELETE FROM ' . $this->table_prefix . tables::TOPICTAGS . '
			WHERE ' . $this->db->sql_in_set('id', $delete_ids);
		$this->db->sql_query($sql);
		$removed_count = $this->db->sql_affectedrows();

		$this->calc_count_tags();

		return $removed_count;
	}

	/**
	* Gets all tags assigned to a topic (and sorts them).
	*
	* @param $topic_id        a single topic ID
	* @param $casesensitive   whether to sort the tags case-sensitively
	* @return array           array of sorted tag names
	*/
	public function get_assigned_tags($topic_id, $casesensitive = false)
	{
		$topic_id = (int) $topic_id;

		// Define SQL query to select tag, for this topic:
		$sql = 'SELECT t.tag, t.tag_lowercase
            FROM ' . $this->table_prefix . tables::TAGS . ' AS t,
                 ' . $this->table_prefix . tables::TOPICTAGS . " AS tt
            WHERE tt.topic_id = $topic_id
                AND t.id = tt.tag_id";

		// Fetch the tags from the database:
		$tagslist = $this->db_helper->get_multiarray_by_fieldnames($sql, array(
				'tag',
				'tag_lowercase'
			));

		// Run the array of tags through the sorter:
		$tagslistSorted = $this->sort_tags($tagslist, $casesensitive);

		// Flatten the array of sorted tags, to return only the tag names
		// (we have no use of the forced-lowercase versions after sorting,
		// and downstream uses, like urlencode(), expect strings from an array
		// not arrays from a multiarray):
		$tagNames = array_map(function($tag) {
			return $tag['tag']; // Extract only the 'tag' value
		}, $tagslistSorted);

		// Return the flattened array of tag names
		return $tagNames;
	}

	/**
	 * This runs the tag suggestions that pop up when you start entering a tag
	 * when editing tags in a post (e.g., you type "h", "e", "l", and if "help"
	 * or "Help" already exists as a tag, it will be suggested as a match).
	 * Gets $count tags that start with $query, ordered by their usage count
	 * (desc). Note that $query needs to be at least 3 characters long.
	 *
	 * @param string $query		prefix of tags to search
	 * @param array $exclude	tags that should be ignored
	 * @param int $count		count of tags to return
	 * @return array			(array('text' => '...'), array('text' => '...'))
	 */
	public function get_tag_suggestions($query, $exclude, $count)
	{
		if (utf8_strlen($query) < 3) {
			return array();
		}
		$exclude_sql = '';
		if (!empty($exclude)) {
			$exclude_sql = ' AND ' . $this->db->sql_in_set('t.tag', $exclude, true, true);
		}
		$sql_array = array(
			'SELECT'	=> 't.tag, t.count',
			'FROM'		=> array(
				$this->table_prefix . tables::TAGS => 't',
			),
			'WHERE'		=> 'LOWER(t.tag) ' . $this->db->sql_like_expression(utf8_strtolower($query) . $this->db->get_any_char()) . "
							$exclude_sql",
			'ORDER_BY'	=> 't.count DESC',
		); 	// We must fetch count, because PostgreSQL needs the context for
		    // ordering. Different databases treat "LIKE" as either case-
		    // sensitive or not, so it's not really of direct use to us, even
			// in phpBB's intermediary `sql_like_expression()` form. We need
		    // to find tags in a case-insensitive manner, as completion
		    // suggestions for the user, but return the real names of these
		    // tags, not their forced-lowercase versions used for comparison.
		$sql = $this->db->sql_build_query('SELECT_DISTINCT', $sql_array);
		$result = $this->db->sql_query_limit($sql, $count);
		$tags = array();
		while ($row = $this->db->sql_fetchrow($result)) {
			$tags[] = array('text' => $row['tag']);
		}
		$this->db->sql_freeresult($result);
		return $tags;
	} // It's unclear why the min. is 3; changing it to 2 above has no effect.

	/**
	 * Assigns exactly the given valid tags to the topic (all other tags are
	 * removed from the topic and if a tag does not exist yet, it will be
	 * created).
	 *
	 * @param int $topic_id			ID of topic
	 * @param array $valid_tags 	validated tag-names
	 * @param bool $casesensitive	whether to permit case-sensitive tags
	 * 								 (e.g. "Turkey" and "turkey")
	 */
	public function assign_tags_to_topic($topic_id, $valid_tags, $casesensitive = false)
	{
		$topic_id = (int) $topic_id;

		$this->remove_all_tags_from_topic($topic_id, false);
		// The "false" there prevents deletion of presently unused tags, which
		// would not be appropriate at this stage.

		// Create in the db any new tags that have been added, and get
		// (possibly updated) tags list:
		$checked_tags = $this->create_missing_tags($valid_tags, $casesensitive);

		// Get IDs of tags:
		$ids = $this->get_existing_tags($checked_tags, true, $casesensitive);
		// `true` here equates to `$only_ids = true`.

		// Create topic_id ←→ tag_id link in TOPICTAGS_TABLE:
		$sql_ary = array();
		foreach ($ids as $id) {
			$sql_ary[] = array(
				'topic_id'	=> $topic_id,
				'tag_id'	=> $id
			);
		}
		$this->db->sql_multi_insert($this->table_prefix . tables::TOPICTAGS, $sql_ary);

		// Garbage collection:
		$this->delete_unused_tags();
		$this->calc_count_tags();
	}

	/**
	 * Finds whether the given tag already exists; if not, creates it in the
	 * database.
	 *
	 * @param array $tags		 	pre-validated tag-names.
	 * @param bool $casesensitive	whether to permit case-sensitive tags
	 * 								 (e.g. "Turkey" and "turkey").
	 * @return array				adjusted tag list for further processing.
	 */
	private function create_missing_tags($tags, $casesensitive = false)
	{

		// Ensure that there isn't a tag twice in the array:
		$tags = array_unique($tags);

		// Get any existing tags matching those requested (case-insensitively
		// by default):
		$existing_tags = $this->get_existing_tags($tags, $casesensitive);

		// Initialize array to store new tags for batch insertion:
		$sql_ary_new_tags = array();

		for ($i = 0, $count = count($tags); $i < $count; $i++) {

			// Prepare lowercase version of the tag for reuse:
			$tag_lowercase = utf8_strtolower($tags[$i]);

			// Set the tag spelling to check against existing tags:
			if (!$casesensitive) { // Case-insensitive is the default.
				$tag_to_check = $tag_lowercase;
				$tag_field = 'tag_lowercase';
			} else {
				$tag_to_check = $tags[$i];
				$tag_field = 'tag';
			}

			// Check whether the tag is already in the existing list:
			$match = $this->in_array_r($tag_to_check, $existing_tags, true, $casesensitive);
			// `true` in that equates to `$strict = true`, i.e. `===` not `==`.

			if ($match !== false) {
				// Case-insensitive mode: replace with the existing tag's correct case:
				if (!$casesensitive && is_string($match)) {
					$tags[$i] = $match;
				}
			} else {
				// Tag doesn't exist already, so prepare it for insertion:
				$sql_ary_new_tags[] = [
					'tag'           => $tags[$i],
					'tag_lowercase' => $tag_lowercase
				];
			}
		} // We use an indexed `for` loop here to operate on the array's real
		  // values, not on a copy of the array. This is less hazardous than
		  // using the `&$tag` approach with an outer `foreach`.

		// Insert any new tags into the database:
		if (!empty($sql_ary_new_tags)) {
			$this->db->sql_multi_insert($this->table_prefix . tables::TAGS, $sql_ary_new_tags);
		}

		// Return the adjusted tag list for further processing, and apply a
		// final deduplication, in case multiple input tags mapped to the same
		// existing tag when the case-sensitivity option is off (the default):
		return array_unique($tags); 		
	}

	/**
	 * Recursively searches for a value in a (possibly multidimensional) array.
	 *
	 * @param mixed $needle			the value to search for
	 * @param array $haystack		array or multiarray to search in
	 * @param bool $strict			If true, both value and type must match
	 * 							 	 (===); if false (default), then type
	 * 							 	 conversion is permitted (==).
	 * @param bool $casesensitive	whether to consider case in comparison
	 * @return mixed				true if match found, false if not; or the
	 * 							 	matching value if extended match is needed
	 */
	private function in_array_r($needle, $haystack, $strict = false, $casesensitive = true)
	{
		foreach ($haystack as $item) {
			if (!is_array($item)) {
				if ($casesensitive) {
					if ($strict) {
						if ($item === $needle) {
							return true;
						}
					} else {
						if ($item == $needle) {
							return true;
						}
					}
				} else {
					if (is_string($item) && is_string($needle)) {
						if (utf8_strtolower($item) === utf8_strtolower($needle)) {
							return $item; // Return the actual match to allow normalization.
						}
					}
				}
			} else {	// Recursively search within array if one was passed:
				if ($this->in_array_r($needle, $item, $strict, $casesensitive)) {
					return true;
				}
			}
		}
		// If no match is found even after iterating through the array:
		return false;
	}

	/**
	 * Gets the existing tags, out of the tags given in $tags, or out of all
	 * existing tags if $tags == null. If $only_ids is set to true, an array
	 * containing only the IDs of the tags will be returned, instead of IDs +
	 * tag names: array(1,2,3,..)
	 *
	 * @param array $tags			tag-names; may be null to get all existing
	 * @param bool $only_ids		whether to return only the tag IDs (true)
	 * 								 or tag names as well (false, default)
	 * @param bool $casesensitive	whether to search by "real" tag name (when
	 * 								true) or by lowercased version (default).
	 * @return array				array of the form array(array('id' => ... ,
									 'tag' => ...), array('id' => ... , 'tag'
									 => ... , 'tag_lowercase' => ...), ...);
									 or array(1,2,3,...) if $only_ids == true
	 */
	public function get_existing_tags($tags = null, $only_ids = false, $casesensitive = false)
	{
		$where = ''; // From all tables by default.

		// Define SQL query to select tags:
		if (!is_null($tags)) {
			if (empty($tags)) {
				// Ensure that empty input array results in empty output array.
				// Note that this case is different from $tags == null where we
				// want to get ALL existing tags.
				return array();
			}
			if (!$casesensitive) {
				$tags = array_map('utf8_strtolower', $tags);
				$where = 'WHERE ' . $this->db->sql_in_set('tag_lowercase', $tags);
			} else {
				$where = 'WHERE ' . $this->db->sql_in_set('tag', $tags);
			}
		}
		$sql = 'SELECT id, tag, tag_lowercase
			FROM ' . $this->table_prefix . tables::TAGS . "
			$where";
		if ($only_ids) {
			return $this->db_helper->get_ids($sql);
		}

		// Fetch the tags from the database:
		return $this->db_helper->get_multiarray_by_fieldnames($sql, array(
				'id',
				'tag',
				'tag_lowercase'
			));
	} // This function, in its "all tags" mode, differs from get_all_tags() in
	  // providing only IDs and tagnames ("real" and lowercased), or IDs only,
	  // if that was chosen; the other function provides IDs, "real" and
	  // lowercase tag names, and count of each tag's uses; it also supports
	  // a number $limit, but not a constraint by specified tag names.

	/**
	 * Gets the topics which are tagged with any or all of the given $tags,
	 * from all forums in which tagging is enabled AND which the user is
	 * allowed to read (BUT exclusive of unapproved topics). These filtering
	 * determinations are handled by other functions called in a chain from
	 * this one.
	 *
	 * @param int $start			start for SQL query
	 * @param int $limit			limit for SQL query
	 * @param array $tags			list of tags to find the topics for
	 * 								 (multiarray)
	 * @param string $mode			AND=all tags must be assigned, OR=at least
	 * 								 one tag needs to be assigned
	 * @param bool $casesensitive	whether the search should be casesensitive
	 * 								 (true) or not (false, default).
	 * @return array				list of topics, each containing all fields
	 * 								 from TOPICS_TABLE (multiarray)
	 */
	public function get_topics_by_tags(array $tags, $start = 0, $limit, $mode = 'AND', $casesensitive = false)
	{
		$sql = $this->get_topics_build_query($tags, $mode, $casesensitive);
		$order_by = ' ORDER BY topics.topic_last_post_time DESC';
		$sql .= $order_by;
		return $this->db_helper->get_array($sql, $limit, $start);
	}

	/**
	 * Counts the topics which are tagged with any or all of the given $tags
	 * from all forums, where tagging is enabled and only those which the user
	 * is allowed to read.
	 *
	 * @param array $tags		the tags to find the topics for
	 * @param $mode				AND(default)=all tags must be assigned, OR=at least one tag needs to be assigned
	 * @param $casesensitive	search case-sensitive if true, insensitive otherwise (default).
	 * @return int				count of topics found
	 */
	public function count_topics_by_tags(array $tags, $mode = 'AND', $casesensitive = false)
	{
		if (empty($tags)) {
			return 0;
		}
		$sql = $this->get_topics_build_query($tags, $mode, $casesensitive);
		$sql = "SELECT COUNT(*) as total_results
			FROM ($sql) a";
		return (int) $this->db_helper->get_field($sql, 'total_results');
	}

	/**
	 * Generates a sql_in_set depending on $casesensitive using tag or tag_lowercase.
	 *
	 * @param array $tags				the tags to build the SQL for
	 * @param boolean $casesensitive	whether to leave the tags as-is (true) or make them lowercase (false)
	 * @return string					the sql_in string depending on $casesensitive using tag or tag_lowercase
	 */
	private function sql_in_casesensitive_tag(array $tags, $casesensitive)
	{
		$tags_copy = $tags;
		if (!$casesensitive) {
			$tag_count = count($tags_copy);
			for ($i = 0; $i < $tag_count; $i++)
			{
				$tags_copy[$i] = utf8_strtolower($tags_copy[$i]);
			}
		}
		if ($casesensitive) {
			return $this->db->sql_in_set(' t.tag', $tags_copy);
		} else {
			return $this->db->sql_in_set('t.tag_lowercase', $tags_copy);
		}
	}

	/**
	 * Gets the forum IDs that the user is allowed to read.
	 *
	 * @return array	forum ids that the user is allowed to read
	 */
	private function get_readable_forums()
	{
		$forum_ary = array();
		$forum_read_ary = $this->auth->acl_getf('f_read');
		foreach ($forum_read_ary as $forum_id => $allowed) {
			if ($allowed['f_read']) {
				$forum_ary[] = (int) $forum_id;
			}
		}

		// Remove double entries
		$forum_ary = array_unique($forum_ary);
		return $forum_ary;
	}

	/**
	 * Get SQL-query source for the topics that reside in forums that the user
	 * can read and which are approved.
	 *
	 * @return string	the generated SQL
	 */
	private function sql_where_topic_access()
	{
		$forum_ary = $this->get_readable_forums();
		$sql_where_topic_access = '';
		if (empty($forum_ary)) {
			$sql_where_topic_access = ' 1=0 ';
		} else {
			$sql_where_topic_access = $this->db->sql_in_set('topics.forum_id', $forum_ary, false, true);
		}
		$sql_where_topic_access .= ' AND topics.topic_visibility = ' . ITEM_APPROVED;
		return $sql_where_topic_access;
	}

	/**
	 * Builds an SQL query that selects all topics assigned with the tags depending on $mode and $casesensitive
	 *
	 * @param array $tags			list of tags (multiarray)
	 * @param string $mode			AND or OR
	 * @param bool $casesensitive	false (default) or true
	 * @param bool $alltags			false (default); if true, fetches all tags
	 								 for user's accessible topics
	 * @return string				'SELECT topics.* FROM ' . TOPICS_TABLE .
	 *								 ' topics WHERE ' . [calculated where]
	 */
	public function get_topics_build_query(array $tags, $mode = 'AND', $casesensitive = false, $alltags = false)
	{
		if (empty($tags) && !$alltags) {
			return 'SELECT topics.* FROM ' . TOPICS_TABLE . ' topics WHERE 0=1';
		} // We use this no-op pseudo-query, instead of returning `null` or
		  // empty, specifically so that a syntatically valid SQL query string
		  // is returned and no conditional test for one is needed elsewhere.

		// Validate mode (force a valid value if something strange was given):
		if ($mode !== 'OR') {
			$mode = 'AND'; // default
		}

		$sql_where_topic_access = $this->sql_where_topic_access();

		if ($alltags) {
			// Fetch all topics that have at least one tag and are user-accessible
			$sql = 'SELECT DISTINCT topics.*
				FROM ' . TOPICS_TABLE . ' topics
				JOIN ' . $this->table_prefix . tables::TOPICTAGS . ' tt ON topics.topic_id = tt.topic_id
				JOIN ' . FORUMS_TABLE . " f ON f.forum_id = topics.forum_id
				WHERE f.rh_topictags_enabled = 1
				AND $sql_where_topic_access";
		} else {
			// Standard tag-based topic query
			$sql_where_tag_in = $this->sql_in_casesensitive_tag($tags, $casesensitive);

			if ('AND' == $mode) {
				$tag_count = count($tags);
				// http://stackoverflow.com/questions/26038114/sql-select-distinct-where-exist-row-for-each-id-in-other-table
				$sql = 'SELECT topics.*
					FROM 	' . TOPICS_TABLE								. ' topics
						JOIN ' . $this->table_prefix . tables::TOPICTAGS	. ' tt ON tt.topic_id = topics.topic_id
						JOIN ' . $this->table_prefix . tables::TAGS			. ' t  ON tt.tag_id = t.id
						JOIN ' . FORUMS_TABLE								. " f  ON f.forum_id = topics.forum_id
					WHERE
						$sql_where_tag_in
						AND f.rh_topictags_enabled = 1
						AND $sql_where_topic_access
					GROUP BY topics.topic_id
					HAVING count(t.id) = $tag_count";
			} else {
				// OR mode, we produce: AND t.tag IN ('tag1', 'tag2', ...)
				$sql_array = array(
					'SELECT'	=> 'topics.*',
					'FROM'		=> array(
						TOPICS_TABLE							=> 'topics',
						$this->table_prefix . tables::TOPICTAGS	=> 'tt',
						$this->table_prefix . tables::TAGS		=> 't',
						FORUMS_TABLE							=> 'f',
					),
					'WHERE'		=> "
						$sql_where_tag_in
						AND topics.topic_id = tt.topic_id
						AND f.rh_topictags_enabled = 1
						AND f.forum_id = topics.forum_id
						AND $sql_where_topic_access
						AND t.id = tt.tag_id
					");
				$sql = $this->db->sql_build_query('SELECT_DISTINCT', $sql_array);
			}
		}
		return $sql;
	}

	/**
	 * Checks whether the given tag is blacklisted.
	 *
	 * @param array $tag	tag (an associative array) to check
	 * @return bool			true if the tag is on the blacklist, false otherwise
	 */
	private function is_on_blacklist($tag)
	{
		$blacklist = json_decode($this->config_text->get(prefixes::CONFIG.'_blacklist'), true);
		foreach ($blacklist as $entry) {
			if ($tag === $this->clean_tag($entry)) {
				return true;
			}
		}

	}

	/**
	 * Checks whether the given tag is whitelisted.
	 *
	 * @param array $tag	tag (an associative array) to check
	 * @return bool			true if the tag is on the whitelist, false otherwise
	 */
	private function is_on_whitelist($tag)
	{
		$whitelist = $this->get_whitelist_tags();
		foreach ($whitelist as $entry) {
			if ($tag === $this->clean_tag($entry)) {
				return true;
			}
		}
	}

	/**
	 * Gets all tags from the whitelist
	 */
	public function get_whitelist_tags()
	{
		return json_decode($this->config_text->get(prefixes::CONFIG . '_whitelist'), true);
	}

	/**
	 * Checks whether the given tag matches the configured regex for valid
	 * tags. The tag is trimmed (to 30 characters by default) before this
	 * check. Also checks whether the tag is whitelisted and/or blacklisted
	 * if one or both lists are enabled.
	 *
	 * @param array $tag		the tag (an associative array) to check
	 * @param bool $is_clean	whether the tag has already been cleaned or not
	 * @return bool				true if the tag matches, false otherwise
	 */
	public function is_valid_tag($tag, $is_clean = false)
	{
		// Use a utility function to trim leading and trailing whitespace from
		// tags, and truncate over-long ones to the 30-character maximum:
		if (!$is_clean) {
			$tag = $this->clean_tag($tag);
		}

		// Test tag against the ACP-configured regex of allowed characters:
		$pattern = $this->config[prefixes::CONFIG.'_allowed_tags_regex'];
		$tag_is_valid = preg_match($pattern, $tag);

		if (!$tag_is_valid) {
			// Failure to conform to that regex is always invalid.
			return false;
		}

		/* At this point, cleaned tag passed allowed-characters regex test. */

		// SQL injection patterns to check (especially since the default regex
		// above is easily altered in dangerous ways by non-expert admins:
		$sql_injection_regex = [
			'/\/\*/',
			'/\b[0-9]=[0-9]/',
			'/\b0x[0-9]+/',
			'/[\'\"](?:admin|member|password|pwd|root|user)@?[\'\"]/i',
			// Note that the above has \' in it (not needed and, depending on
			// flavor, even invalid in regex, but required inside a PHP array
			// element because that is '-delimited; PHP will remove that /
			// from the /' when passing the regex.
			'/\b(?:ADDR|ASCII|BENCHMARK|CAST|CH(?:A?)R|COMPRESS|CONCAT|CONVERT|COUNT|ENCODE|FILE|HEX|IF|IIF|MD5|NAME|NVL|PASSWORD|PG_SLEEP|REQUEST|SCHEMA|SHA1|SLEEP|SUBSTRING|SUM|USER|VALUE(?:S?)|VARCHAR|VERSION)\(/i',
			'/\ball_(?:tables|coll)/i',
			'/\bAND\b.*?=/i',
			'/\bBULK\bINSERT/i',
			'/\bCOLLATE\bSQL_/i',
			'/COMPONENT_VERSION/i',
			'/\b(?:CREATE|DELETE)\b(?:FUNCTION|TABLE)/i',
			'/db_password/i',
			'/dbms_pipe/i',
			'/\b(?:DECLARE|SET)\b@/i',
			'/\bDELETE\bFROM\b([A-Z0-9_]+?)\b/i',
			'/\bDROP\b(?:\*|TABLE|MEMBER(?:S?))/i',
			'/\bEXEC\b(?:sp_|xp_)/i',
			'/\b(?:FROM|INSERT\bINTO)\b(?:ACCOUNT(?:S?)|ADMIN(?:S?)|GROUP(?:S?)|ID(?:S?)|MEMBER(?:S?)|PASSWORD(?:S?)|PWD(?:S?)|USER(?:S?)|USERNAME(?:S?))/i',
			'/\bFROM\bDUAL\b/i',
			'/\b(?:GROUP|ORDER)\bBY/i',
			'/\bINSERT\bINTO\b([A-Z0-9_]+?)\b/i',
			'/\bLIMIT\b[0-9]+,[0-9]+/i',
			'/LOAD(?:\s+|_)(?:DATA|FILE)/i',
			'/master(?:s?)\.\./i',
			'/\bmb_users\b/i',
			'/mysql\.user/i',
			'/\bNULL\bWHERE/i',
			'/\bOR\b.*?=/i',
			'/\bPASSWORD\bFROM/i',
			'/\bSELECT\b(?:\*|UTL_|SYS\.|CASE WHEN|LOAD)/i',
			'/\bsp_configure/i',
			'/sys(?:\.?)objects/i',
			'/sys\.sql/i',
			'/table_name/i',
			'/\bUNION\b(?:ALL|SELECT)/i',
			'/\bUPDATE\b([A-Z0-9_]+?)\bSET\b/i',
			'/\bWAITFOR\bDELAY/i',
			'/\bWHERE\b(?:ID|MEMBER|USER|USERNAME)/i',
			'/\bxp_(?:cmd|reg|serv|avail|login|make|ntsec|term|add|web)/i',
			'/\bALTER\b/i'
		];

		// Enable the folowing debugger if a regex you add here leads to a
		// PHP error showing up in the page:
		/*
		foreach ($sql_injection_regex as $pattern) {
			if (@preg_match($pattern, '') === false) { // Suppress errors with @ and check return value
				throw new \Exception("Invalid regex pattern: $pattern");
			}
		}
		*/

        //Reject tag if it matches any of the above patterns:
		foreach ($sql_injection_regex as $pattern) {
			if (preg_match($pattern, $tag)) {
				// Reject tag if it matches any potentially dangerous pattern:
				return false;
			}
		}

		/* At this point, tag passed all regex tests. */

		// Check tag against blacklist:
		if ($this->config[prefixes::CONFIG.'_blacklist_enabled']) {
			if ($this->is_on_blacklist($tag)) {
				// Tag is blacklisted, so is invalid:
				return false;
			}
			// Not blacklisted, so do nothing here.
		}

		/* At this point, tag passed regex tests, and isn't blacklisted
		   (or blacklist is disabled). */

		// Check tag against whitelist:
		if ($this->config[prefixes::CONFIG.'_whitelist_enabled']) {
			if ($this->is_on_whitelist($tag)) {
				// Tag is whitelisted, so is necessarily valid:
				return true;
			}
			// Whitelist is enabled but tag isn't in it, so is invalid:
			return false;
		}

		/* At this point, tag passed regex tests, isn't blacklisted, and
		   doesn't have a whitelist to conform to, so is valid. */

		return true;
	}

	/**
	 * Splits the given tags into valid and invalid ones.
	 *
	 * @param array $tags	an indexed array of potential tags
	 * @return array		array('valid' => array(), 'invalid' => array())
	 */
	public function split_valid_tags($tags)
	{
		$re = array(
			'valid'		=> array(),
			'invalid'	=> array()
		);
		foreach ($tags as $tag) {
			$tag = $this->clean_tag($tag);
			if ($this->is_valid_tag($tag, true)) {
				$type = 'valid';
			} else {
				$type = 'invalid';
			}
			$re[$type][] = $tag;
		}
		return $re;
	}

	/**
	 * Trims the tag to 30 characters, replaces spaces with hyphens, if
	 * configured to do so, and counters some basic SQL injection hack
	 * techniques.
	 *
	 * @param array $tag	the tag (an associative array) to clean
	 * @return array		cleaned tag (still an associative array)
	 */
	public function clean_tag($tag)
	{
		// Cut off any leading/trailing space:
		$tag = trim($tag);

		// The db field is max. 30 characters!
		$tag = utf8_substr($tag, 0, 30);
		// If this is changed from 30 to another number, then matching changes
		// need to be made in adm/style/topictags_manage_tags.html and in
		// language/xx/topictags_acp.php files!

		// Might have a space at the end now, so trim again:
		$tag = trim($tag);

		if ($this->config[prefixes::CONFIG.'_convert_space_to_hyphen']) {
			$tag = str_replace(' ', '-', $tag);
		}

		// Convert problematic or reserved sequences to safe alternatives.
		$conversions = [
			'--' => '-',  // Replace double hyphen with single hyphen.
			'@@' => '@',  // Replace double at-sign with single at-sign.
			'||' => '-',  // Replace double pipe with hyphen.
			'|' => '-',  // Replace single pipe with hyphen.
			'//'  => '-',  // Replace double forward-slashes with hyphen.
			'/'  => '-',  // Replace single forward-slash with hyphen.
			'\\\\' => '-',  // Replace double backslashes with hyphen.
			'\\' => '-',  // Replace single backslash with hyphen.
			// Backslashes are doubled there because PHP needs them escaped.
			','  => '.'  // Replace comma with dot.
		]; // We don't want "--", so turned "//", etc., into "-" not "--".
		// This is all done agnostically with regard to whether any of the
		// resulting characters will actually be permitted in the admin-
		// chosen "permissible characters" regex. The purpose here is
		// neutralizing common SQL-injection patterns no matter what.
		// If you have a special use case for permitting `/`, `\`, or `|`
		// in tag names, then you can comment out some specific "Replace
		// single ..." lines above, but be aware of potential SQL injection
		// risks.

		foreach ($conversions as $search => $replace) {
			$tag = str_replace($search, $replace, $tag);
		}
		// Do it again, in case any of the above operations collapsed something
		// like `---` into the undesired `--`:
		foreach ($conversions as $search => $replace) {
			$tag = str_replace($search, $replace, $tag);
		}

		return $tag;
	}

	/**
	 * Checks whether tagging is enabled in the given forum.
	 *
	 * @param int $forum_id		the ID of the forum
	 * @return bool				true if tagging is enabled in the given forum,
	 * 							 false if not
	 */
	public function is_tagging_enabled_in_forum($forum_id)
	{
		$field = 'rh_topictags_enabled';
		$sql = "SELECT $field
			FROM " . FORUMS_TABLE . '
			WHERE ' . $this->db->sql_build_array('SELECT', array('forum_id' => (int) $forum_id));
		$status = (int) $this->db_helper->get_field($sql, $field);
		return $status > 0;
	}

	/**
	 * Enables tagging engine in all forums (not categories or links).
	 *
	 * @return int	number of affected forums (should be the count of all
	 * 				 forums (type FORUM_POST ))
	 */
	public function enable_tags_in_all_forums()
	{
		return $this->set_tags_enabled_in_all_forums(true);
	}

	/**
	 * Enables/disables tagging engine in all forums (not categories or links).
	 *
	 * @param bool $enable		true to enable or false to disable tagging
	 * @return into				number of affected forums (should be the count
	 * 							 of all forums (type FORUM_POST ))
	 */
	private function set_tags_enabled_in_all_forums($enable)
	{
		if ($enable) {
			$rh_topictags_enabled_value = 1;
		} else {
			$rh_topictags_enabled_value = 0;
		}
		
		$sql_ary = array(
			'rh_topictags_enabled' => $rh_topictags_enabled_value
		);
		
		if ($enable) {
			$rh_topictags_enabled_condition = '0';
		} else {
			$rh_topictags_enabled_condition = '1';
		}

		$sql = 'UPDATE ' . FORUMS_TABLE . '
			SET ' . $this->db->sql_build_array('UPDATE', $sql_ary) . '
			WHERE forum_type = ' . FORUM_POST . '
				AND rh_topictags_enabled = ' . $rh_topictags_enabled_condition;
		
		$this->db->sql_query($sql);
		$affected_rows = $this->db->sql_affectedrows();
		$this->calc_count_tags();
		return (int) $affected_rows;
	}

	/**
	 * Disables tagging engine in all forums (not categories or links).
	 *
	 * @return int	number of affected forums (should be the count of all
	 * 				 forums (type FORUM_POST ))
	 */
	public function disable_tags_in_all_forums()
	{
		return $this->set_tags_enabled_in_all_forums(false);
	}

	/**
	 * Checks whether all forums have the given status of the tagging engine
	 * (enabled/disabled)
	 *
	 * @param boolean $status	true to check for enabled, false to check for
	 * 							 disabled tagging
	 * @return boolean			true if for all forums tagging is state $status
	 */
	private function is_status_in_all_forums($status)
	{
		if ($status) {
			$rh_topictags_enabled_value = '0';
		} else {
			$rh_topictags_enabled_value = '1';
		}

		$sql_array = array(
			'SELECT'	=> 'COUNT(*) as all_not_in_status',
			'FROM'		=> array(
				FORUMS_TABLE => 'f',
			),
			'WHERE'		=> 'f.rh_topictags_enabled = ' . $rh_topictags_enabled_value . '
				AND forum_type = ' . FORUM_POST,
		); // If any are disabled, is_enabled_in_all_forums() returns false.
		
		$sql = $this->db->sql_build_query('SELECT', $sql_array);
		$all_not_in_status = (int) $this->db_helper->get_field($sql, 'all_not_in_status');
		return $all_not_in_status == 0;
	}

	/**
	 * Checks whether tagging is enabled for all forums (not categories or
	 * links).
	 *
	 * @return boolean	true if for all forums tagging is enabled (type
	 * 					 FORUM_POST ))
	 */
	public function is_enabled_in_all_forums()
	{
		return $this->is_status_in_all_forums(true);
	}

	/**
	 * Checks whether tagging is disabled for all forums (not categories or
	 * links).
	 *
	 * @return boolean	true if for all forums tagging is disabled (type
	 * 					 FORUM_POST ))
	 */
	public function is_disabled_in_all_forums()
	{
		return $this->is_status_in_all_forums(false);
	}

	/**
	 * Counts how often each tag is used (minus any usage in tagging-disabled
	 * forums) and stores it for each tag.
	 */
	public function calc_count_tags()
	{
		$sql_array = array(
			'SELECT'	=> 'id',
			'FROM'		=> array(
				$this->table_prefix . tables::TAGS => 't',
			),
		);
		$sql = $this->db->sql_build_query('SELECT_DISTINCT', $sql_array);
		$tag_ids = $this->db->sql_query($sql);

		while ($tag = $this->db->sql_fetchrow($tag_ids)) {
			$tag_id = $tag['id'];
			$sql = 'SELECT COUNT(tt.id) as count
				FROM ' . TOPICS_TABLE . ' topics,
					' . FORUMS_TABLE . ' f,
					' . $this->table_prefix . tables::TOPICTAGS . ' tt
				WHERE tt.tag_id = ' . $tag_id . '
					AND topics.topic_id = tt.topic_id
					AND f.forum_id = topics.forum_id
					AND f.rh_topictags_enabled = 1';
			$this->db->sql_query($sql);
			$count = $this->db->sql_fetchfield('count');

			$sql = 'UPDATE ' . $this->table_prefix . tables::TAGS . '
				SET count = ' . $count . '
				WHERE id = ' . $tag_id;
			$this->db->sql_query($sql);
		}
	}

	/**
	 * Gets the topic IDs to which the given tag ID is assigned.
	 *
	 * @param int $tag_id	the ID of the tag
	 * @return array		array of ints (the topic IDs)
	 */
	private function get_topic_ids_by_tag_id($tag_id)
	{
		$sql_array = array(
			'SELECT'	=> 'tt.topic_id',
			'FROM'		=> array(
				$this->table_prefix . tables::TOPICTAGS => 'tt',
			),
			'WHERE'		=> 'tt.tag_id = ' . ((int) $tag_id),
		);
		$sql = $this->db->sql_build_query('SELECT_DISTINCT', $sql_array);
		return $this->db_helper->get_ids($sql, 'topic_id');
	}

	/**
	 * Merges two tags, by assigning all topics of tag_to_delete_id to the
	 * tag_to_keep_id and then deletes the tag_to_delete_id.
	 * NOTE: Both tags must exist and this is not checked again!
	 *
	 * @param int $tag_to_delete_id		the ID of the tag to delete
	 * @param string $tag_to_keep		must be valid
	 * @param int $tag_to_keep_id		the ID of the tag to keep
	 * @return int						the new count of assignments of the kept tag
	 */
	public function merge($tag_to_delete_id, $tag_to_keep, $tag_to_keep_id)
	{
		$tag_to_delete_id = (int) $tag_to_delete_id;
		$tag_to_keep_id = (int) $tag_to_keep_id;

		// Delete assignments where the new tag is already assigned:
		$topic_ids_already_assigned = $this->get_topic_ids_by_tag_id($tag_to_keep_id);
		if (!empty($topic_ids_already_assigned)) {
			$sql = 'DELETE FROM ' . $this->table_prefix . tables::TOPICTAGS. '
				WHERE ' . $this->db->sql_in_set('topic_id', $topic_ids_already_assigned) . '
					AND tag_id = ' . (int) $tag_to_delete_id;
			$this->db->sql_query($sql);
		}
		// Renew assignments where the new tag is not yet assigned:
		$sql_ary = array(
			'tag_id' => $tag_to_keep_id,
		);
		$sql = 'UPDATE ' . $this->table_prefix . tables::TOPICTAGS . '
			SET  ' . $this->db->sql_build_array('UPDATE', $sql_ary) . '
			WHERE tag_id = ' . (int) $tag_to_delete_id;
		$this->db->sql_query($sql);

		$this->delete_tag($tag_to_delete_id);
		$this->calc_count_tags();
		return $this->count_topics_by_tags(array($tag_to_keep), 'AND', true);
	}

	/**
	 * Deletes the given tag and all its assignments.
	 *
	 * @param int $tag_id
	 * @return void
	 */
	public function delete_tag($tag_id)
	{
		$sql = 'DELETE FROM ' . $this->table_prefix . tables::TOPICTAGS . '
			WHERE tag_id = ' . ((int) $tag_id);
		$this->db->sql_query($sql);

		$sql = 'DELETE FROM ' . $this->table_prefix . tables::TAGS . '
			WHERE id = ' . ((int) $tag_id);
		$this->db->sql_query($sql);
	}

	/**
	 * Renames the tag
	 *
	 * @param int $tag_id				the ID of the tag
	 * @param string $new_name_clean	the new name of the tag already cleaned
	 * @return int						the count of topics that are assigned to the tag
	 */
	public function rename($tag_id, $new_name_clean)
	{
		$sql_ary = array(
			'tag'			=> $new_name_clean,
			'tag_lowercase'	=> utf8_strtolower($new_name_clean),
		);
		$sql = 'UPDATE ' . $this->table_prefix . tables::TAGS . '
			SET ' . $this->db->sql_build_array('UPDATE', $sql_ary) . '
			WHERE id = ' . ((int) $tag_id);
		$this->db->sql_query($sql);
		return $this->count_topics_by_tags(array($new_name_clean), 'AND', true);
	}

	/**
	 * Gets the tag name corresponding to a tag ID
	 *
	 * @param int $tag_id	the ID of the tag
	 * @return string		the name of the tag
	 */
	public function get_tag_by_id($tag_id)
	{
		$sql_array = array(
			'SELECT'	=> 't.tag',
			'FROM'		=> array(
				$this->table_prefix . tables::TAGS => 't',
			),
			'WHERE'		=> 't.id = ' . ((int) $tag_id),
		);
		$sql = $this->db->sql_build_query('SELECT_DISTINCT', $sql_array);
		return $this->db_helper->get_field($sql, 'tag', 1);
	}

    /**
     * Sorts a multiarray of tags, in language-and-locale-aware alphabetical
	 * order, then in human-friendly "natural" numeric order for groups of
	 * tags that contain numeric strings. More difficult than it sounds.
     * 
     * @param array $tagslist		list of tags to be sorted (multiarray)
     * @param bool $casesensitive	whether to perform case-sensitive sorting;
	 * 									default false					
	 * @param $asc					order direction; default true = ascending,
	 * 									false = descending
     * @return array				sorted tagslist
     */
	public function sort_tags($tagslist, $casesensitive = false, $asc = true)
	{
		/* By default, this extension defers to the current system
		   language_locale.charset alphabetization rules. Instead, you can set
		   something specific here, e.g. 'en_US.UTF-8', to conform sorting to a
		   particular language and country's norms. This might be needed in a
		   case of mismatch between server configuration and target audience,
		   like running a German board on a shared hosting provider in Sweden.
		   BE SURE that your system supports the localization you choose!
		   
		   We cannot use phpBB variables like $user_lang_name or S_USER_LANG
		   because those are simplifications like "en", not PHP-understood
		   localization names.
		*/

		if (empty($tagslist)) {
			return [];
		} // Not sure how this could happen, but handle it.

		// Stage 1: Pre-sorting prep.
		
		// Save the current locale before changing it:
		$original_locale = setlocale(LC_COLLATE, 0);
		// 0 (WITHOUT quotation marks!) gets the current locale setting (set by
		// phpBB or some other extension, often same as server default, but
		// often modified).
		
		try {	// Keep other logic in a "sandbox" to protect setlocale ...

			// You can change the following to something specific, but ensure
			// your system actually supports it and it'ss entered correctly!
			$desired_locale = $original_locale;
			// Using '0' (WITH single-quotes) is supposed to mean use the
			// system default, but this actually fails on many systems,
			// and will generate numerous repetitious error log entries
			// because of the frequency of this function's use.

			// Attempt to set the desired locale:
			$setlocale_result = setlocale(LC_COLLATE, $desired_locale);

			// Check if the locale was set correctly; we are going to be cautious
			// because locale strings can be complicated and easy to get wrong:
			if ($setlocale_result === false) {
				// If setting locale fails, log warning and fallback to PHP's
				// internal default:
				//error_log("RH Topic Tags (service/tags_manager.php: sort_tags): Locale setting failed for '$desired_locale'. Falling back to PHP default.");
				// This error logging is off by default but can be enabled;
				// probably of use in testing on a new server deployment.
				setlocale(LC_COLLATE, 'C');
			} else {
				// If locale was set, verify that it matches the requested one:
				$current_locale = setlocale(LC_COLLATE, 0); // Change as needed
				if ($current_locale !== $desired_locale) {
					// If locales don't match, log warning and fallback to
					// PHP's default:
					//error_log("RH Topic Tags (service/tags_manager.php: sort_tags): Failed to apply locale '$desired_locale'. Current locale: $current_locale. Falling back to PHP default.");
					// This error logging is off by default but can be enabled;
					// probably of use in testing on a new server deployment.
					setlocale(LC_COLLATE, 'C');
				}
		}

			// Stage 2: Locale-compliant alphabetical sorting.

			// Determine which field to use based on case-sensitivity:
			if ($casesensitive) {
				$tag_field = 'tag';  // "Official" tag names as saved in the db.
			} else {
				$tag_field = 'tag_lowercase';  // Db already has LC version, too.
			}

			// Perform both language-specific sorting (via strcoll) and
			// natural numeric sorting (via strnatcasecmp) in one pass:
			uasort($tagslist, function($a, $b) use ($tag_field) {
			// NOT usort()! Must use uasort() because it preserves the relationship
			// between array elements like tag names and their IDs, which are
			// metadata relating to URLs, not indicators of sorting order.

				// Sort by alphabetic comparison, using language- and region-
				// specific collation (according to the setlocale localization
				// above); store result in a variable:
				return strcoll($a[$tag_field], $b[$tag_field]);
				// Returns to uasort(), which is sorting $tagslist in-place.
			});

			$tagslistAlphabetic = array_values($tagslist);
			// Any jumbling of array in the sorting process is cleaned up:
			// tags are re-indexed in their sort order, starting from 0, with
			// no gaps or out-of-order sequences in their index numbering.
			// This is needed or some downstream uses will break.

			// Stage 3: "Natural" (human-friendly) numeric sorting, as-needed.

			// This is to stop treating `tag02` as less than `tag` because of
			// the leading zero, and stop treating `version-2.11.0` as less
			// than `version-2.9.0` because `1` comes before `9`.
			//
			// This is a complex process (in PHP, at any rate), because the
			// natural-numeric-sorting functions are also alphabetic sorters
			// but not locale-aware, so would undo strcoll()'s work above,
			// and the alphabetic sorters are all also numeric sorters, but
			// not "natural". We have no choice but to isolate groups of
			// numeric tags and sort them internally, then return them as
			// chunks back into their group position in the alphabetical sort.

			// Initialize some variables we'll need:
			$saved_prefix = null; // Non-numeric tag portion before a number.
			$current_chunk = []; // Temporary grouping of contiguous tags
			                     // `$current_chunk = array();` in long syntax.
			$is_numeric_chunk = false; // True if chunk is numeric tag series.
			$tagslistChunked = []; // Chunks progressively re-merged in order.

			// Group into content-based chunks.
			foreach ($tagslistAlphabetic as $tag) {
			$tag_value = $tag[$tag_field]; // Retrieve the tag name.
				// Check whether the tag has a numeric portion:
				if (preg_match('/^(.*?)(\d+(?:\.\d+)*)(.*)$/u', $tag_value, $matches)) {
					[$full_match, $prefix, $numeric, $suffix] = $matches;
					// Assigns regex capture groups to variables to reuse.

					// Determine if this numeric tag belongs to the same prefix
					// group (from an earlier foreach loop iteration); if so, 
					// concatenate tag into the same numeric chunk:
					if ($saved_prefix === $prefix) {
						// Merge to current numeric chunk and leave it current:
						$current_chunk[] = $tag;
						// Confirm this as a numeric chunk for numeric sorting:
						$is_numeric_chunk = true;
					} else {
						// Detect a numeric chunk (now ended) from previous
						// iteration and numerically sort it internally:
						if ($is_numeric_chunk) {
							uasort($current_chunk, function($a, $b) use ($tag_field) {
								// strnatcmp does the "natural" number sorting.
								return strnatcmp($a[$tag_field], $b[$tag_field]);
							}); // Returns to uasort, which is sorting in-place.
						}
						// Append current chunk (whether it was numeric or not)
						// onto array of ended chunks:
						$tagslistChunked = array_merge($tagslistChunked, $current_chunk);
						// Start a new chunk:
						$current_chunk = [$tag];
						$is_numeric_chunk = false; // Contains a single numeric
						 // tag so far but does not yet qualify as a "numeric
						 // chunk" for numeric sorting.
						$saved_prefix = $prefix; // [Re]set prefix to compare.
					}
				} else {
					// Handle non-numeric tags. These have no $prefix, etc.
					if ($is_numeric_chunk) {
						// Append current numeric chunk to ended-chunks array:
						$tagslistChunked = array_merge($tagslistChunked, $current_chunk);
						// Start a new non-numeric chunk:
						$current_chunk = [$tag];
						$is_numeric_chunk = false;
					} else {
						// Merge to current non-numeric chunk, leave it open:
						$current_chunk[] = $tag;
					}
				}
			}

			// Because the foreach loop simply terminates after processing the
			// final tag entry in $tagslistAlphabetic, but chunk merger doesn't
			// happen until after an iteration has started, we must handle the
			// final chunk directly:
			if ($is_numeric_chunk) {
				uasort($current_chunk, function($a, $b) use ($tag_field) {
					return strnatcmp($a[$tag_field], $b[$tag_field]);
				}); // Returns to uasort, which is sorting in-place.
			}
			$tagslistChunked = array_merge($tagslistChunked, $current_chunk);
			// This finishes $tagslistChunked, which has been progressively
			// built by appending each chunk (numeric or otherwise) in the
			// top-down order in which tags were chunked. The numeric sort
			// operations have only altered tag order inside numeric chunks,
			// with all chunks retaining their alphabetically sorted order.

			// Re-index the tags array from the post-sorting chunked version,
			// while preserving the multiarray structure of the content:
			$tagslistSorted = array_values($tagslistChunked);
			// Ensures that the list starts with index 0 and progresses
			// in sorted numeric order without gaps, or index numbers out of
			// sequence. "Clean" array required by some downstream uses.

			// "Stage 3" does not handle recursive checks for additional
			// numeric strings in the $suffix to sort (if also found in
			// groups), e.g. in tags like `Squid-Game-season-01-episode-04`.
			// Some recursion of this sort is in development as of 2025-01.

		} finally {

			// Always restore the original locale, even if an error occurred:
			// First a quick test for an edge case:
			if (!$original_locale) {
				// If locale wasn't set properly even before we started, due to
				// system misconfiguration, handle that case.  First log it:
				//error_log("RH Topic Tags (service/tags_manager.php: sort_tags): Locale setting '$original_locale' empty. Falling back to default locale.");
				// This error logging is off by default but can be enabled, and
				// is probably of use in testing on a new server deployment.

				// Now set it back to PHP default and hope for the best:
				setlocale(LC_COLLATE, 'C');
			} else {
				// Otherwise, trust the $original_locale we saved at the top
				setlocale(LC_COLLATE, $original_locale);
			}
			// We do this because that's a global setting and various other
			// things may be making use of this in different ways. This must be
			// done before returning out of this function for any reason.
		}

		// If descending order was requested, reverse the sorted array:
		if (!$asc) {
			$tagslistSorted = array_reverse($tagslistSorted, true);
			// The second argument, `true`, ensures that the array keys are
			// preserved, because they are object IDs and such, with metadata
			// purposes, not indicators of list ordering.
		}

		return $tagslistSorted;

		/* In cases where setlocale() fails or cannot be set to a desired
		   locale, we could potentially fallback to strnatcasecmp() in place
		   of strcoll(), though this would not have the nuances of the latter's
		   language-specific collation. This would be some work, so will not
		   be implemented without clear demand for it.
		   
		   Using PHP Collation to perform the sorting wouold be superior even
		   to strcoll(). But we can't depend on this newer approach being
		   available because it's not part of PHP itself but of the Intl
		   extension, which may not be installed. If it's not, this results in
		   a "Server 500" error that crashes the entire phpBB board! It might
		   be possible to test for it and use it if available but fall back to
		   strcoll() if not; this is under development testing.
		   
		   It's also not practicable to have the database itself do the
		   collating via the SQL query that fetches the tags, since collation
		   names between MySQL, PostreSQL, etc., are not in agreement.
		*/
	}

	/**
	 * Gets ALL tags, unfiltered, from the database; sorts them in an array.
	 *
	 * @param int $start			start for SQL query
	 * @param int $limit			limit for SQL query
	 * @param $sort_field			the db column to order by; tag (default) or count
	 * @param $asc					order direction; true (default) = ASC, false = DESC
	 * @param bool $casesensitive	whether to perform case-sensitive fetching
	 * @param bool $humsort			whether to perform human-friendly sorting
	 * @return array				array of tags
	 */
	public function get_all_tags($start = 0, $limit = NULL, $sort_field = 'tag', $asc = true, $casesensitive = false, $humsort = true)
	{
		// Validate the sort field using a whitelist, to prevent SQL injection
		// or simply invalid-input errors. Whitelist presently permits fetching
		// by tag name (default) or by count of tag uses; easily extended:
		$allowed_sort_fields = ['tag', 'count', 'id'];
		// Force `tag` as default if value is invalid by the above test:
		if (!in_array($sort_field, $allowed_sort_fields, true)) {
			$sort_field = 'tag';
		} // If a page is asking for `count`, it's probably to list tags by use
		  // frequency, so `$asc = false` is probably also desired for that.

		// Set the database fetch direction:
		if ($asc) {
			$direction = 'ASC'; // Ascending: 0→9, A→Z
		} else {
			$direction = 'DESC'; // Descending: Z→A, 9→0
		} // Fetching in DESC order will be overriden by some of the later
		  // sorting operations, so an `$asc = false` request will be
		  // fulfilled by selectively reversing sorted lists in such cases.

		// Define SQL query to fetch tags from the database:
		$sql = 'SELECT * FROM ' . $this->table_prefix . tables::TAGS . '
			ORDER BY ' . $sort_field . ' ' . $direction;
		// $start and $limit are NOT used in the db fetch, because sorting
		// options below will only work on the whole tags list, not chunks
		// artificially pre-paginated by start/limit chunking in db-entry order
		// (i.e. by date of creation).  $start and $limit are instead passed
		// later to array_slice() if pagination is needed. If there arises
		// a need for literally limiting the db fetch returns, this should be
		// done with get_existing_tags() (or a new function) which does not
		// perform any tagslist-wide sorting. Presently, there is no use case
		// for that in the extension (as of 2025-01).

		// Define the field names to fetch from the database:
		$field_names = array(
			'id',
			'tag',
			'tag_lowercase',
			'count'
		);

		// Fetch the tags from the database:
		$tagslist = $this->db_helper->get_multiarray_by_fieldnames($sql, $field_names);

		if ($sort_field == 'count') {
			// Mustn't be run through the sorter (even if sorting was requested
			// inappropriately); it's a numeric count, not any kind of label: 
			$humsort = false;
		}
		if ($humsort) {
			// Run the array of tags through the sorter for locale-aware alphabetic
			// and human-friendly numeric sorting of tag names:
			$tagslistSorted = $this->sort_tags($tagslist, $casesensitive, $asc);

			// Return the sorted tags list (paginated if $start and $limit
			// were imposed).
			return array_slice($tagslistSorted, $start, $limit);
		}

		// Otherwise, return the raw database-order list (whether ASC or DESC),
		// i.e. the order in which tags were created:
		return array_slice($tagslist, $start, $limit);
	} // This function, in "all tags" mode, differs from get_existing_tags()
	  // in providing IDs, "real" tag names, lowercase versions of tag names,
	  // and count of each tag's uses; it also supports a numeric limit for
	  // pagination, and has some sorting options. The other function returns
	  // only ID and "real" + lowercased tag names (or IDs only, as an option),
	  // can limit by specified tag names but not number, and does no sorting.

	/**
	 * Gets the count of ALL tags, unfiltered.
	 *
	 * @return int	the count of all tags
	 */
	public function count_tags()
	{
		$sql = 'SELECT COUNT(*) as count_tags FROM ' . $this->table_prefix . tables::TAGS;
		return (int) $this->db_helper->get_field($sql, 'count_tags');
	}
}
