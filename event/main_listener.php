<?php
/**
*
* @package phpBB Extension - RH Topic Tags
* @copyright © 2014 Robert Heim; revision 2024 by S. McCandlish
* @license http://opensource.org/licenses/gpl-2.0.php GNU General Public License v2
*
*/

namespace robertheim\topictags\event;

/**
* @ignore
*/
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use robertheim\topictags\prefixes;
use robertheim\topictags\permissions;

/**
* Event listener
*/
class main_listener implements EventSubscriberInterface
{

	public static function getSubscribedEvents()
	{
		return array(
			'core.user_setup'                                => 'load_language_on_setup',
			'core.permissions'                               => 'permissions',
			'core.index_modify_page_title'                   => 'index_modify_page_title',
			'core.modify_posting_parameters'                 => 'modify_posting_parameters',
			'core.posting_modify_template_vars'              => 'posting_modify_template_vars',
			'core.viewforum_modify_topicrow'                 => 'viewforum_modify_topicrow',
			'robertheim.topictags.viewforum_modify_topicrow' => 'viewforum_modify_topicrow',
			'core.viewtopic_assign_template_vars_before'     => 'viewtopic_assign_template_vars_before',
			'core.submit_post_end'                           => 'submit_post_end',
			'core.delete_topics_before_query'                => 'delete_topics_before_query',
		);
	}

	/** @var \phpbb\config\config */
	protected $config;

	/** @var \robertheim\topictags\service\tags_manager */
	protected $tags_manager;

	/** @var \phpbb\controller\helper */
	protected $helper;

	/** @var \phpbb\request\request */
	protected $request;

	/** @var \phpbb\user */
	protected $user;

	/** @var \phpbb\template\template */
	protected $template;

	/** @var \phpbb\auth\auth */
	protected $auth;

	/** @var \robertheim\topictags\service\tagcloud_manager */
	protected $tagcloud_manager;

	/**
	 * Constructor
	 */
	public function __construct(
		\phpbb\config\config $config,
		\robertheim\topictags\service\tags_manager $tags_manager,
		\phpbb\controller\helper $helper,
		\phpbb\request\request $request,
		\phpbb\user $user,
		\phpbb\template\template $template,
		\phpbb\auth\auth $auth,
		\robertheim\topictags\service\tagcloud_manager $tagcloud_manager
	)
	{
		$this->config = $config;
		$this->tags_manager = $tags_manager;
		$this->helper = $helper;
		$this->request = $request;
		$this->user = $user;
		$this->template = $template;
		$this->auth = $auth;
		$this->tagcloud_manager = $tagcloud_manager;
	}

	/**
	 * Reads all tags from HTTP POST request variable 'rh_topictags' and splits
	 * them by the separator (default: comma ',') and trims them.
	 * NOTE: These tags might be dirty!
	 *
	 * @return array	array of potentially dirty tags
	 */
	private function get_tags_from_post_request()
	{
		$tags_string = utf8_normalize_nfc($this->request->variable('rh_topictags', '', true));
		$tags_string = rawurldecode(base64_decode($tags_string));

		if ('' === $tags_string)
		{
			return array();
		}

		$tagsJson = json_decode($tags_string, true);
		$tags = array();

		for ($i = 0, $count = sizeof($tagsJson); $i<$count; $i++)
		{
			$tags[] = trim($tagsJson[$i]['text']);
		}

		return $tags;
	} // Note "post" in the name of this function refers to the HTTP POST
	  // input method, not to posts in the phpBB sense of posts and topics.

	/**
	 * Event: core.load_language_on_setup
	 */
	public function load_language_on_setup($event)
	{
		$lang_set_ext = $event['lang_set_ext'];
		$lang_set_ext[] = array(
			'ext_name' => 'robertheim/topictags',
			'lang_set' => 'topictags',
		);
		$event['lang_set_ext'] = $lang_set_ext;
	}

	/**
	 * Loads the permissions.
	 *
	 * @param mixed $event	the event data
	 */
	public function permissions($event)
	{
		$permissions = $event['permissions'];
		$permissions[permissions::ADMIN_EDIT_TAGS] = array('lang' => 'ACL_'.utf8_strtoupper(permissions::ADMIN_EDIT_TAGS), 'cat' => 'posting');
		$permissions[permissions::MOD_EDIT_TAGS] = array('lang' => 'ACL_'.utf8_strtoupper(permissions::MOD_EDIT_TAGS), 'cat' => 'post_actions');
		$permissions[permissions::USE_TAGS] = array('lang' => 'ACL_'.utf8_strtoupper(permissions::USE_TAGS), 'cat' => 'post');
		$event['permissions'] = $permissions;
	}

	/**
	 * Event: core.index_modify_page_title
	 *
	 * @param mixed $event	the event data
	 */
	public function index_modify_page_title($event)
	{
		if ($this->config[prefixes::CONFIG . '_display_tagcloud_on_index'])
		{
			$this->template->assign_vars(array(
				'RH_TOPICTAGS_DISPLAY_TAGCLOUD_ON_INDEX'	=> true,
				'RH_TOPICTAGS_TAGCLOUD_LINK'				=> $this->helper->route('robertheim_topictags_controller'),
			));
			$this->tagcloud_manager->assign_tagcloud_to_template();
		}
	}

	/**
	 * Event: core.modify_posting_parameters
	 *
	 * Validate the tags and create an error if any tag is invalid.
	 *
	 * @param mixed $event	the event data
	 */
	public function modify_posting_parameters($event)
	{
		if ($this->auth->acl_gets(permissions::USE_TAGS, permissions::ADMIN_EDIT_TAGS, permissions::MOD_EDIT_TAGS))
		{

			$data = $event->get_data();
			$tags = $this->get_tags_from_post_request();

			$all_tags = $this->tags_manager->split_valid_tags($tags);
			$invalid_tags = $all_tags['invalid'];

			if (sizeof($invalid_tags))
			{
				$this->user->add_lang_ext('robertheim/topictags', 'topictags');
				$data['error'][] = $this->user->lang('RH_TOPICTAGS_TAGS_INVALID', join(', ', $invalid_tags));
			}

			$event->set_data($data);
		}
	}

	/**
	 * Event: core.postingsubmit_post_end
	 *
	 * After a posting we assign the tags to the topic
	 *
	 * @param mixed $event	the event data
	 */
	public function submit_post_end($event)
	{
		if ($this->auth->acl_gets(permissions::USE_TAGS, permissions::ADMIN_EDIT_TAGS, permissions::MOD_EDIT_TAGS))
		{
			$event_data = $event->get_data();
			$data = $event_data['data'];
			$mode = $event_data['mode'];
			if ($this->is_new_topic($mode) || $this->is_edit_first_post($mode, $data))
			{
				$tags = $this->get_tags_from_post_request();
				$all_tags = $this->tags_manager->split_valid_tags($tags);
				$valid_tags = $all_tags['valid'];
				$this->tags_manager->assign_tags_to_topic($data['topic_id'], $valid_tags);
			}
		}
	}

	/**
	 * Event: core.posting_modify_template_vars
	 *
	 * Send the tags on edits or preview to the template.
	 *
	 * @param mixed $event	the event data
	 */
	public function posting_modify_template_vars($event)
	{
		if ($this->auth->acl_gets(permissions::USE_TAGS, permissions::ADMIN_EDIT_TAGS, permissions::MOD_EDIT_TAGS))
		{
			$data = $event->get_data();
			$forum_id = $data['forum_id'];

			if (!$this->tags_manager->is_tagging_enabled_in_forum($forum_id))
			{
				return;
			}

			$topic_id = false;

			if (empty($data['mode']) || $data['mode'] == 'reply')
			{
				return;
			}

			$mode = $data['mode'];

			if (!empty($data['post_data']['topic_id']))
			{
				$topic_id = $data['post_data']['topic_id'];
			}

			$is_edit_first_post = $topic_id && $this->is_edit_first_post($mode, $data['post_data']);
			if ($this->is_new_topic($mode) || $is_edit_first_post)
			{
				$page_data = $this->get_template_data_for_topic($topic_id, $is_edit_first_post);
				$data['page_data'] = array_merge($data['page_data'], $page_data);
				$event->set_data($data);
			}
		}
	} // Note: "post" in 'post_data' means the HTTP POST method of data input;
	  // not referring to posts in the phpBB sense of posts, topics & forums.

	/**
	 * Checks whether the mode indicates a new topic.
	 *
	 * @param string $mode	the mode
	 * @return boolean		true if mode == post (indicating a new topic); false otherwise
	 */
	private function is_new_topic($mode)
	{
		return $is_new_topic = $mode == 'post';
	} // Note: "post" in $mode's context means "creating a post" (vs. "editing
	  // a post" or "replying to a post"); it doesn't refer to the HTTP POST
	  // method of data input, nor to distinctions betweeen phpBB posts, topics
	  // and forums. Rather, it means establishing a new topic with an all-new
	  // post, as the first post (to which tags attach) in the new topic.

	/**
	 * Checks whether the HTTP POST data indicates that the first post in the topic is being edited.
	 *
	 * @param string $mode		the event's data mode
	 * @param array $post_data	the event's HTTP POST data, expected to include:
	 * 							- 'post_id' (int): ID of the forum post being edited
	 * 							- 'topic_first_post_id' (int): ID of the 1st post in the topic
	 * @return boolean			true if the topic's first post is being edited; false otherwise
	 */
	private function is_edit_first_post($mode, array $post_data)
	{
		$post_id = $topic_first_post_id = false;
		if (!empty($post_data['topic_first_post_id']))
		{
			$topic_first_post_id = $post_data['topic_first_post_id'];
		}
		if (!empty($post_data['post_id']))
		{
			$post_id = $post_data['post_id'];
		}
		return $mode == 'edit' && $post_id && $post_id == $topic_first_post_id;
	} // As noted above, the word "post" is trebly "operator-overloaded". In
	  // this function, it means a phpBB content post (versus a topic with
	  // posts or a forum of topics) in $post_id and $topic_first_post_id. But
	  // it means "HTTP POST data from a submitted form" in $post_data. Then
	  // in the context of $mode, it is the opposite of the 'edit' tested for
	  // here (and would indicate creation of a new post rather than editing
	  // of an existing one). These variables would be better renamed to be
	  // distinctive and clear, but that would have to be done perfectly across
	  // multiple files in this extension's codebase, so it's easier to just
	  // insert some explanatory notes like these.

	/**
	 * Calculates the template data for the topic.
	 *
	 * @param int $topic_id					the ID of the topic
	 * @param boolean $is_edit_first_post	whether it is a first post edit or not
	 * @return array						the page data
	 */
	private function get_template_data_for_topic($topic_id, $is_edit_first_post)
	{
		$page_data = array();
		$page_data['RH_TOPICTAGS_SHOW_FIELD'] = true;

		// Do we have some preview data?
		$tags = array();
		if ($this->request->is_set_post('rh_topictags'))
		{
			// Use data from post-request:
			$tags = $this->get_tags_from_post_request();
		}
		else if ($is_edit_first_post)
		{
			// Use data from db:
			$tags = $this->tags_manager->get_assigned_tags($topic_id);
		}

		$page_data['RH_TOPICTAGS'] = base64_encode(rawurlencode(json_encode($tags)));

		$page_data['RH_TOPICTAGS_ALLOWED_TAGS_REGEX'] = $this->config[prefixes::CONFIG . '_allowed_tags_regex'];
		$page_data['RH_TOPICTAGS_CONVERT_SPACE_TO_HYPHEN'] = $this->config[prefixes::CONFIG . '_convert_space_to_hyphen'] ? 'true' : 'false';

		$page_data['S_RH_TOPICTAGS_WHITELIST_ENABLED'] = $this->config[prefixes::CONFIG . '_whitelist_enabled'];

		if ($this->config[prefixes::CONFIG . '_whitelist_enabled'])
		{
			$page_data['S_RH_TOPICTAGS_WHITELIST_ENABLED'] = true;
			$tags = $this->tags_manager->get_whitelist_tags();
			for ($i = 0, $size = sizeof($tags); $i < $size; $i++)
			{
				$this->template->assign_block_vars('rh_topictags_whitelist', array(
					'LINK' => '#',
					'NAME' => $tags[$i],
				));
			}
		}
		else
		{
			$page_data['RH_TOPICTAGS_ALLOWED_TAGS_EXP'] = $this->config[prefixes::CONFIG . '_allowed_tags_exp_for_users'];
		}
		$page_data['S_RH_TOPICTAGS_INCLUDE_NG_TAGS_INPUT'] = true;
		$page_data['S_RH_TOPICTAGS_INCLUDE_CSS'] = true;
		return $page_data;
	}

	/**
	 * Event: core.viewforum_modify_topicrow
	 *
	 * Gets and assigns tags to topic-row template -> RH_TOPICTAGS_TAGS.
	 *
	 * Note that we assign a string which includes the <a href ...> link
	 * already, because we cannot assign sub-blocks before the outer block
	 * with assign_block_vars(...) and the event is before the assignment.
	 *
	 * @param mixed $event	the event data
	 */
	public function viewforum_modify_topicrow($event)
	{
		if ($this->config[prefixes::CONFIG.'_display_tags_in_viewforum'])
		{
			$data = $event->get_data();
			$topic_id = (int) $data['row']['topic_id'];
			$forum_id = (int) $data['row']['forum_id'];

			if ($this->tags_manager->is_tagging_enabled_in_forum($forum_id))
			{
				$tags = $this->tags_manager->get_assigned_tags($topic_id);
				if (!empty($tags))
				{
					// We cannot use assign_block_vars('topicrow.tags', ...)
					// here, because the block 'topicrow' is not yet assigned.
					// Add links:
					$this->assign_tags_to_template('rh_tags_tmp', $tags);
					// small_tag.html might want to use our extension's CSS:
					$this->template->assign_var('S_RH_TOPICTAGS_INCLUDE_CSS', true);
					$rendered_tags = $this->template->assign_display('@robertheim_topictags/small_tag.html');
					// Remove temporary data:
					$this->template->destroy_block_vars('rh_tags_tmp');

					// Assign the template data:
					$data['topic_row']['RH_TOPICTAGS_TAGS'] = $rendered_tags;

					$event->set_data($data);
				}
			}
		}
	}

	/**
	 * Assigns the given tags to the template block
	 *
	 * @param string $block_name	the name of the template block
	 * @param array $tags			the tags to assign
	 */
	private function assign_tags_to_template($block_name, array $tags)
	{
		foreach ($tags as $tag)
		{
			$this->template->assign_block_vars($block_name, array (
				'NAME'	=> $tag,
				'LINK'	=> $this->helper->route('robertheim_topictags_show_tag_controller', array(
					'tags'	=> urlencode($tag),
				)),
			));
		}
	}

	/**
	 * Event: core.viewtopic_assign_template_vars_before
	 *
	 * Assigns tags to topic template and header meta.
	 *
	 * @param mixed $event	the event data
	 */
	public function viewtopic_assign_template_vars_before($event)
	{
		$data = $event->get_data();
		$topic_id = (int) $data['topic_id'];
		$forum_id = (int) $data['forum_id'];

		if ($this->tags_manager->is_tagging_enabled_in_forum($forum_id))
		{
			$tags = $this->tags_manager->get_assigned_tags($topic_id);
			if (!empty($tags))
			{
				$this->assign_tags_to_template('rh_topic_tags', $tags);
				$this->template->assign_vars(array(
					'RH_TOPICTAGS_SHOW'	=> true,
					'META'				=> '<meta name="keywords" content="' . join(', ', $tags) . '">',
				));
				// tags.html might want to use our extension's CSS:
				$this->template->assign_var('S_RH_TOPICTAGS_INCLUDE_CSS', true);
			}
		}
	}

	/**
	 * Event: core.delete_topics_before_query
	 *
	 * Prunes tags when topic is deleted.
	 *
	 * @param mixed $event	the event data
	 */
	public function delete_topics_before_query($event)
	{
		$data = $event->get_data();
		$topic_ids = $data['topic_ids'];
		$this->tags_manager->remove_all_tags_from_topics($topic_ids, true);
	}
}
