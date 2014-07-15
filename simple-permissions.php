<?php
/**
 * @package Simple-Permissions
 * @version 1.1.0
 */
/*
Plugin Name: Simple Permissions
Plugin URI: http://wordpress.org/plugins/simple-permissions/
Description: Create simple permission groups for reading or editing posts.
Author: Michael George
Version: 1.1.0

    This program is free software; you can redistribute it and/or modify
    it under the terms of the GNU General Public License as published by
    the Free Software Foundation; either version 2 of the License, or
    (at your option) any later version.

    This program is distributed in the hope that it will be useful,
    but WITHOUT ANY WARRANTY; without even the implied warranty of
    MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
    GNU General Public License for more details.

    You should have received a copy of the GNU General Public License
    along with this program; if not, write to the Free Software
    Foundation, Inc., 59 Temple Place, Suite 330, Boston, MA  02111-1307  USA
*/

if ( ! class_exists( "SimplePermissions" ) ) {
	class SimplePermissions {
		var $adminOptionsName = "SimplePermissionsAdminOptions";
		var $join;
		var $where;

		function SimplePermissions() { //constructor
			$this->__construct();
		}

		function __construct() {
			$this->spGetAdminOptions();
		}

		//Returns an array of admin options
		function spGetAdminOptions() {
			$simplePermissionsAdminOptions = array(
								"groups" => array(
												array( "id" => 0, "name" => "Public", "enabled" => true, "members" => array(), "limitCats" => array() )
												,array( "id" => 1, "name" => "Logged In Users", "enabled" => true, "members" => array(), "limitCats" => array() )
												)
								,"redirectPageID" => ""
								,"allowedRole" => "contributor" //as of 1.1.0
								);
			$devOptions = get_option( $this->adminOptionsName );
			if ( ! empty( $devOptions ) ) {
				foreach ( $devOptions as $optionName => $optionValue ) {
					$simplePermissionsAdminOptions[$optionName] = $optionValue;
				}
			}
			update_option( $this->adminOptionsName, $simplePermissionsAdminOptions );
			$sortGroups = $simplePermissionsAdminOptions['groups'];
			$simplePermissionsAdminOptions['groups'] = array();
			foreach ( $sortGroups as $group ) {
				$simplePermissionsAdminOptions['groups'][$group['id']] = $group;
			}
			return $simplePermissionsAdminOptions;
		}

		//delete all settings as well as all post meta data
		function spDeleteItAll() {
			global $wpdb;
			$simplePermissionsAdminOptions = array(
								"groups" => array(
												array( "id" => 0, "name" => "Public", "enabled" => true, "members" => array(), "limitCats" => array() )
												,array( "id" => 1, "name" => "Logged In Users", "enabled" => true, "members" => array(), "limitCats" => array() )
												)
								,"redirectPageID" => ""
								,"allowedRole" => "contributor"
								);
			update_option( $this->adminOptionsName, $simplePermissionsAdminOptions );
			$sql = "DELETE FROM " . $wpdb->postmeta . " WHERE meta_key IN ('simplePermissions_readGroupIDs', 'simplePermissions_writeGroupIDs')";
			$return = $wpdb->query( $sql );
			return $return;
		}

		//return the highest group id++
		function spGetNextGroupID() {
			$devOptions = $this->spGetAdminOptions();
			$nextGroupID = 0;
			foreach ( $devOptions['groups'] as $group ) {
				if ( $group['id'] >= $nextGroupID ) {
					$nextGroupID = $group['id'];
					$nextGroupID++;
				}
			}
			return $nextGroupID;
		}

		//Store the permissions in the meta table
		function spUpdatePost( $post_id ) {
			$readGroupIDs = array();
			$writeGroupIDs = array();
			foreach ( $_POST as $key => $value){
				if ( preg_match( '/^simplePermissions_/', $key ) ) {
					if ( $value ) {
						$parsedPost = explode( '_', $key );
						if ( $parsedPost[3] == 'read' ) {
							$readGroupIDs[] = $parsedPost[2];
						} else if ( $parsedPost[3] == 'write' ) {
							$writeGroupIDs[] = $parsedPost[2];
						}
					}
				}
			}
			delete_post_meta( $post_id, 'simplePermissions_readGroupIDs' );
			delete_post_meta( $post_id, 'simplePermissions_writeGroupIDs' );
			foreach ( $readGroupIDs as $group ) {
				add_post_meta( $post_id, 'simplePermissions_readGroupIDs', $group );
			}
			foreach ( $writeGroupIDs as $group ) {
				add_post_meta( $post_id, 'simplePermissions_writeGroupIDs', $group );
			}

			return true;
		}

		//Get permissions for post
		//Returns array (group/user id int, group/user name str, permission str)
		function spGetPermissions( $post_id ) {
			$devOptions = $this->spGetAdminOptions();
			$readGroups = get_post_meta( $post_id, 'simplePermissions_readGroupIDs' );
			$writeGroups = get_post_meta( $post_id, 'simplePermissions_writeGroupIDs' );

			$returnValue = array();

			if ( count( $writeGroups ) > 0 ) {
				foreach ( $writeGroups as $group ) {
					if ( $devOptions['groups'][$group]['enabled'] ) {
						$returnValue[] = array( "id" => $group, "name" => $devOptions['groups'][$group]['name'], "permission" => "write" );
					}
				}
			}
			if ( count( $readGroups ) > 0 ) {
				foreach ( $readGroups as $group ) {
					if ( $devOptions['groups'][$group]['enabled'] ) {
						if ( ! in_array( array( "id" => $group, "name" => $devOptions['groups'][$group]['name'], "permission" => "write" ), $returnValue ) ) {
							$returnValue[] = array( "id" => $group, "name" => $devOptions['groups'][$group]['name'], "permission" => "read" );
						}
					}
				}
			}
			if ( ! count( $returnValue ) > 0 ) {
				$returnValue[] = array( "id" => 0, "name" => "public", "permission" => "write" );
			}

			return $returnValue;
		}

		//function to see if a user can view, edit, delete post
		//@param array $allcaps All the capabilities of the user
		//@param array $cap		[0] Required capability
		//@param array $args	[0] Requested capability
		//						[1] User ID
		//						[2] Associated object ID
		function spUserCanDo( $allcaps, $cap, $args ) {
			$protectedOperations = array(
										'delete_page'
										,'delete_post'
										,'edit_page'
										,'edit_post'
										,'read_post'
										,'read_page'
										);

			//if we are not checking for a specific post, do nothing
			if ( ! isset( $args[2] ) || ! is_numeric( $args[2] ) ) {
				return $allcaps;
			}

			//Bail out if operation isn't protected
			if ( ! in_array( $args[0], $protectedOperations ) ) {
				return $allcaps;
			}

			//Bail out if user can activate plugins, which is only
			//available to admins and super admins
			if ( $allcaps['activate_plugins'] ) {
				return $allcaps;
			}

			//set the cap to false until we prove it's true
			foreach ( $cap as $thiscap ) {
				unset( $allcaps[$thiscap] );
			}

			$groupPermissions = $this->spGetPermissions( $args[2] );
			$devOptions = $this->spGetAdminOptions();

			if ( count( $groupPermissions ) > 0 ) {
				foreach ( $groupPermissions as $perm ) {
					if ( in_array( $perm['id'], array( 0, 1 ) ) || in_array( $args[1], $devOptions['groups'][$perm['id']]['members'] ) ) {
						if ( preg_match( '/^read_/', $args[0] ) ) {
							//if just reading, as long as a perm is there, it's okay
							foreach ( $cap as $thiscap ) {
								if ( preg_match( '/^read_/', $thiscap ) ) {
									$allcaps[$thiscap] = true;
								}
							}
							return $allcaps;
						} else {
							if ( $perm['permission'] == 'write' ) {
								//has to be there and be 'write'
								foreach ( $cap as $thiscap ) {
									$allcaps[$thiscap] = true;
								}
								return $allcaps;
							}
						}
					}
				}
			} else {
				//no group permissions, so it must be public from this end, let wordpress handle it
				//this really shouldn't happen as spGetPermissions should return "public" at least
				foreach ( $cap as $thiscap ) {
					$allcaps[$thiscap] = true;
				}
				return $allcaps;
			}
			return $allcaps;
		}

		function spOverride404() {
			global $wp_query;
			global $post;
			global $is404Check;

			if ( $wp_query->is_404 == true ) {
				$is404Check = true;
				$devOptions = $this->spGetAdminOptions();
				$postid = url_to_postid( "http" . ( isset($_SERVER['HTTPS'] ) && $_SERVER['HTTPS'] != 'off' ? "s" : "" ) . "://" . $_SERVER["SERVER_NAME"] . $_SERVER['REQUEST_URI'] );
				if ( $postid != 0 ) {
					$redirecturl = get_permalink( $devOptions['redirectPageID'] );
					if ( $redirecturl !== false ) {
						$is404Check = false;
						wp_redirect( $redirecturl, 301 );
						exit;
					}
				}
			}
		}

		function spCustomJoin( $join ) {
			global $wpdb;
			global $is404Check;

			if ( ! $is404Check ) {
				$newjoin = " LEFT JOIN sp_metaTableName AS sp_mt1 ON (sp_postTableName.ID = sp_mt1.post_id AND sp_mt1.meta_key = 'simplePermissions_readGroupIDs') ";
				$newjoin .= " LEFT JOIN sp_metaTableName AS sp_mt2 ON (sp_postTableName.ID = sp_mt2.post_id AND sp_mt2.meta_key = 'simplePermissions_writeGroupIDs')";
				$join .= $newjoin;
				$join = str_replace( 'sp_metaTableName', $wpdb->postmeta, $join );
				$join = str_replace( 'sp_postTableName', $wpdb->posts, $join );
			}
			return $join;
		}

		function spCustomWhere( $where ) {
			global $is404Check;

			if ( ! $is404Check ) {
				$groupMemberships = array();
				$devOptions = $this->spGetAdminOptions();
				if ( is_user_logged_in() ) {
					$current_user = wp_get_current_user();
					$userID = $current_user->ID;
					foreach ( $devOptions['groups'] as $group ) {
						if ( in_array( $userID, $group['members'] ) && $group['enabled'] ) {
							$groupMemberships[] = $group['id'];
						}
					}
					$groupMemberships[] = 0; //Public group
					$groupMemberships[] = 1; //Logged in users group
				} else {
					$groupMemberships[] = 0; //Public group
				}

				$newwhere .= " AND ( ( sp_mt1.post_id IS NULL ";
				$newwhere .= "		AND sp_mt2.post_id IS NULL ";
				$newwhere .= "   ) ";
				foreach ( $groupMemberships as $groupID ) {
					$newwhere .= " OR ( (`sp_mt1`.`meta_key` = 'simplePermissions_readGroupIDs' AND CAST(`sp_mt1`.`meta_value` AS CHAR) = '" . $groupID . "') ";
					$newwhere .= "   OR (`sp_mt2`.`meta_key` = 'simplePermissions_writeGroupIDs' AND CAST(`sp_mt2`.`meta_value` AS CHAR) = '" . $groupID . "') ) ";
				}
				$newwhere .= " ) ";
				$where .= $newwhere;
			}
			return $where;
		}

		//If permissions for more than one group are set on posts, we get duplicates, so this removes them
		function spSearchDistinct() {
			return "DISTINCT";
		}

		//Nabbed from http://wordpress.stackexchange.com/questions/41548/get-categories-hierarchical-order-like-wp-list-categories-with-name-slug-li
		//as of 1.1.0
		function spHierarchicalCategoryTree( $cat, $group, $depth = 0 ) {
			$devOptions = $this->spGetAdminOptions();
			//echo "<!-- $cat, $depth -->\r";
			$next = get_categories( 'hide_empty=0&orderby=name&order=ASC&parent=' . $cat );
			if( $next ) {
				for ( $i = 0; $i < $depth; $i++ ) {
					echo "\t";
				}
				echo "<ul>\r";
				foreach( $next as $cat ) {
					$inArr = in_array( $cat->term_id, $group['limitCats'] );
					for ( $i = 0; $i <= $depth; $i++ ) {
						echo "\t";
					}
					echo "<li><input type='checkbox' name='simplePermissionsLimitCats[]' value='" . $cat->term_id . "'" . ( $inArr ? " checked" : "" ) . " /><strong>";
					for ( $i = 0; $i < $depth; $i++ ) {
						echo "-&nbsp;";
					}
					echo $cat->name . "</strong>";
					$this->spHierarchicalCategoryTree( $cat->term_id, $group, $depth + 1 );
					for ( $i = 0; $i <= $depth; $i++ ) {
						echo "\t";
					}
					echo "</li>\r";
				}
				for ( $i = 0; $i < $depth; $i++ ) {
					echo "\t";
				}
				echo "</ul>\r";
			}
		}

		//Exclude categories from edit page
		//as of 1.1.0
		function spExcludeCategories( $exclusions, $args ) {
			//see if we are on edit screen, if so, bail out
			global $pagenow;
			if ( $pagenow != 'post.php' ) {
				return $exclusions;
			}
			$devOptions = $this->spGetAdminOptions();
			$user = wp_get_current_user();

			$excludedCats = array();
			foreach ( $devOptions['groups'] as $group ) {
				if ( in_array( $user->ID, $group['members'] ) ) {
					foreach ( $group['limitCats'] as $cat ) {
						$excludedCats[] = $cat;
					}
				}
			}
			// if the exclude list is empty, we send everything back the way it came in
			if ( empty( $excludedCats ) ) {
				return $exclusions;
			}

			$exclusions .= " AND ( t.term_id NOT IN (" . implode( ",", $excludedCats ) . ") )";
			return $exclusions;
		}

		//Gets the settings link to show on the plugin management page
		//Thanks to "Floating Social Bar" plugin as the code is humbly taken from it
		function spSettingsLink( $links ) {
			$setting_link = sprintf( '<a href="%s">%s</a>', add_query_arg( array( 'page' => 'simple-permissions.php' ), admin_url( 'options-general.php' ) ), __( 'Settings', 'Simple Permissions' ) );
			array_unshift( $links, $setting_link );
			return $links;
		}

		//Prints out the admin page
		//Since 1.0.0
		function spPrintAdminPage() {
			$devOptions = $this->spGetAdminOptions();
			$workingURL = $_SERVER["REQUEST_URI"];
			echo "<!-- " . print_r( $_POST, true ) . " -->\r";

			if ( isset( $_POST['update_simplePermissionsGroupSettings'] ) ) {
				if ( isset( $_POST['simplePermissionsGroupID'] )
						&& ! isset( $devOptions['groups'][(int)$_POST['simplePermissionsGroupID']] )
					) {
						$devOptions['groups'][(int)$_POST['simplePermissionsGroupID']] = array( "id" => (int)$_POST['simplePermissionsGroupID'], "name" => "", "enabled" => true, "members" => array(), "limitCats" => array() );
				}
				if ( isset( $_POST['simplePermissionsGroupID'] )
						&& isset( $devOptions['groups'][(int)$_POST['simplePermissionsGroupID']] )
						&& isset( $_POST['simplePermissionsNewGroupName'] )
						&& isset( $_POST['simplePermissionsOldGroupName'] )
						&& $_POST['simplePermissionsOldGroupName'] != 'public'
						&& $_POST['simplePermissionsOldGroupName'] != 'Logged In Users'
						&& $_POST['simplePermissionsNewGroupName'] != 'public'
						&& $_POST['simplePermissionsNewGroupName'] != 'Logged In Users'
						&& $_POST['simplePermissionsNewGroupName'] != $_POST['simplePermissionsOldGroupName']
					) {
						$devOptions['groups'][(int)$_POST['simplePermissionsGroupID']]['name'] = $_POST['simplePermissionsNewGroupName'];
						unset( $_GET['spEditGroup'] );
				}

				if ( isset( $_POST['simplePermissionsGroupMembers'] ) ) {
					$devOptions['groups'][(int)$_POST['simplePermissionsGroupID']]['members'] = array();
					//Changed regex on following from /[\s,]+/ to /[\n\r\f]+/ to allow spaces to be used in usernames
					//as of 1.1.0
					$members = preg_split( '/[\n\r\f]+/', $_POST['simplePermissionsGroupMembers'] );
					foreach ( $members as $member ) {
						$wpUserData = get_user_by( 'login', $member );
						if ( ! $wpUserData === false ) {
							if ( ! in_array( $wpUserData->ID, $devOptions['groups'][(int)$_POST['simplePermissionsGroupID']]['members'] ) ) {
								$devOptions['groups'][(int)$_POST['simplePermissionsGroupID']]['members'][] = $wpUserData->ID;
							}
						}
					}
					unset( $_GET['spEditGroup'] );
				}

				if ( isset( $_POST['simplePermissionsLimitCats'] ) ) {
					foreach ( $_POST['simplePermissionsLimitCats'] as $cat ) {
						echo "<!-- found cat $cat -->\r";
						if ( ! in_array( $cat, $devOptions['groups'][(int)$_POST['simplePermissionsGroupID']]['limitCats'] ) ) {
							$devOptions['groups'][(int)$_POST['simplePermissionsGroupID']]['limitCats'][] = (int)$cat;
						}
					}
				} else if ( isset( $_POST['simplePermissionsGroupID'] ) && $_POST['simplePermissionsGroupID'] != 'new' ) {
					$devOptions['groups'][(int)$_POST['simplePermissionsGroupID']]['limitCats'] = array();
				}

				if ( isset( $_POST['spDeleteGroupConfirmed'] ) ) {
					$devOptions['groups'][(int)$_POST['spDeleteGroupConfirmed']]['enabled'] = false;
					unset( $_GET['spDeleteGroup'] );
				}

				if ( isset( $_POST['simplePermissionsRedirectPageID'] ) ) {
					$devOptions['redirectPageID'] = $_POST['simplePermissionsRedirectPageID'];
				}

				if ( isset( $_POST['simplePermissionsAllowedRole'] ) ) {
					$devOptions['allowedRole'] = $_POST['simplePermissionsAllowedRole'];
				}
				$updated = update_option( $this->adminOptionsName, $devOptions );
			} else if ( isset( $_GET['spDeleteItAll'] ) && $_GET['spDeleteItAll'] == 1 ) {
				$updated = $this->spDeleteItAll();
			}

			if ( isset( $updated ) && $updated !== false && isset( $_GET['spDeleteItAll'] ) && $_GET['spDeleteItAll'] == 1 ) {
				echo "<div class='updated'><p><strong>All settings and all post permissions deleted.</strong></p></div>\r";
				$workingURL = spDelArgFromURL( $_SERVER["REQUEST_URI"], array( 'spDeleteItAll' ) );
				unset( $_GET['spDeleteItAll'] );
				$devOptions = $this->spGetAdminOptions();
			} else if ( isset( $updated ) && $updated === false && isset( $_GET['spDeleteItAll'] ) && $_GET['spDeleteItAll'] == 1 ) {
				global $wpdb;
				echo "<div class='updated'><p><strong>Settings where deleted, but post permissions were NOT reset.</strong></p><p>You can try again or run this sql manually.</p><pre>DELETE FROM " . $wpdb->postmeta . " WHERE meta_key IN ('simplePermissions_readGroupIDs', 'simplePermissions_writeGroupIDs')</pre></div>\r";
				$workingURL = spDelArgFromURL( $_SERVER["REQUEST_URI"], array( 'spDeleteItAll' ) );
				unset( $_GET['spDeleteItAll'] );
				$devOptions = $this->spGetAdminOptions();
			} else if ( isset( $updated ) && $updated ) {
				echo "<div class='updated'><p><strong>Settings Updated.</strong></p></div>\r";
				$workingURL = spDelArgFromURL( $_SERVER["REQUEST_URI"], array( 'spDeleteGroup', 'spEditGroup' ) );
			} else if ( isset( $updated ) && ! $updated ) {
				echo "<div class='updated'><p><strong>Settings failed to update.</strong></p></div>\r";
			}
?>
<div id="simple-permissions_option_page" style="width:80%">
<form method="post" action="<?php echo spDelArgFromURL( $_SERVER["REQUEST_URI"], array( 'spDeleteGroup', 'spEditGroup' ) ); ?>">
<input type='hidden' name='update_simplePermissionsGroupSettings' value='1'>
<h2>Simple Permissions Settings</h2><?php
			if ( ! isset( $_GET['spEditGroup'] ) && ! isset( $_GET['spDeleteGroup'] ) ) {
				//some re-ordering so that things are alphabetical, except we put public and logged in users at the end
				$sortGroups = array();
				$key = spMDArraySearch( $groupPermissions, 'name', 'Public' );
				$sortGroups[] = $devOptions['groups'][$key];
				unset( $devOptions['groups'][$key] );
				$key = spMDArraySearch( $groupPermissions, 'name', 'Logged In Users' );
				$sortGroups[] = $devOptions['groups'][$key];
				unset( $devOptions['groups'][$key] );
				$grpNames = array();
				foreach ( $devOptions['groups'] as $key => $row ) {
					$grpNames[$key] = $row["name"];
				}
				array_multisort( $grpNames, SORT_ASC, SORT_STRING | SORT_FLAG_CASE, $devOptions['groups'] );
				foreach ( $sortGroups as $group ) {
					$devOptions['groups'][] = $group;
				}

				echo "<h2>Groups<h2>\r";
				echo "<table id='simplePermissionsGroupsTable' border=1 style='border-collapse: collapse; border: 1px solid black;'>\r";
				echo "<thead style='background: lightgray;'>\r";
				echo "\t<tr><th style='padding: 3px;'>Name</th><th style='padding: 3px;'>Members</th><th colspan=2 style='padding: 3px;'>Options</th></tr>\r";
				echo "</thead>\r";
				echo "<tbody>\r";
				echo "\t<tr><td colspan=4 style='padding: 3px;'><a href='" . $_SERVER["REQUEST_URI"] . "&spEditGroup=new'>New Group</a></td></tr>\r";
				foreach ( $devOptions['groups'] as $group ) {
					if ( $group['enabled'] ) {
						echo "\t<tr><td style='padding: 3px;'><strong>" . $group['name'] . "</strong></td><td style='padding: 3px;'>";
						if ( $group['id'] == 0 ) {
							echo "Everyone, logged in or not</td><td style='padding: 3px;'></td><td style='padding: 3px;'></td></tr>\r";
						} else if ( $group['id'] == 1 ) {
							echo "All logged in users</td><td style='padding: 3px;'></td><td style='padding: 3px;'></td></tr>\r";
						} else {
							$memberCount = count( $group['members'] );
							if ( $memberCount > 3 ) {
								for ( $i = 0; $i < 3; $i++ ) {
									$wpUserData = get_userdata( $group['members'][$i] );
									if ( ! $wpUserData === false ) {
										echo $wpUserData->user_login . ", ";
									} else {
										$i--;
									}
								}
								echo $memberCount - 3 . " more";
							} else {
								$i = 0;
								foreach ( $group['members'] as $member ) {
									$i++;
									$wpUserData = get_userdata( $member );
									if ( ! $wpUserData === false ) {
										echo $wpUserData->user_login;
										if ( $i < $memberCount ) {
											echo ", ";
										}
									}
								}
							}
							echo "</td><td style='padding: 3px;'><a href='" . $_SERVER["REQUEST_URI"] . "&spEditGroup=" . $group['id'] . "'>Edit</a></td><td style='padding: 3px;'><a href='" . $_SERVER["REQUEST_URI"] . "&spDeleteGroup=" . $group['id'] . "'>Delete</a></td></tr>\r";
						}
					}
				}
				if ( count( $devOptions['groups'] ) > 2 ) {
					echo "\t<tr><td colspan=4 style='padding: 3px;'><a href='" . $_SERVER["REQUEST_URI"] . "&spEditGroup=new'>New Group</a></td></tr>\r";
				}
				echo "</tbody>\r";
				echo "</table>\r";

				echo "<h2>Redirect page</h2>\r";
				echo "<p>This is the page/post ID of the page/post users will be redirected to when they don't have permission to view a page.</p>\r";
				echo "<input id='simplePermissionsRedirectPageID' type='text' name='simplePermissionsRedirectPageID' value='" . $devOptions['redirectPageID'] . "' style='width: 100px;'>\r";
				echo "<br>\r";
				echo "<h2>Limit permission changes</h2>\r";
				echo "<p>By default, anyone who can edit a post can change the permissions. Choose another role here to limit changes to users who have that role or higher.</p>\r";
				echo "<select id='simplePermissionsAllowedRole' name='simplePermissionsAllowedRole'>\r";
				echo "\t<option value='administrator'" . ( $devOptions['allowedRole'] == 'administrator' ? " selected" : "" ) . ">Administrators</option>\r";
				echo "\t<option value='editor'" . ( $devOptions['allowedRole'] == 'editor' ? " selected" : "" ) . ">Editors</option>\r";
				echo "\t<option value='author'" . ( $devOptions['allowedRole'] == 'author' ? " selected" : "" ) . ">Authors</option>\r";
				echo "\t<option value='contributor'" . ( $devOptions['allowedRole'] == 'contributor' ? " selected" : "" ) . ">Contributors</option>\r";
				echo "</select>\r";
				echo "<br><br>\r";
				echo "<input type='submit' value='Save'>\r";
				echo "<br><br>\r";
				echo "<h2>Delete everything</h2>\r";
				echo "<p>In some cases you may wish to delete all settings and saved permissions. The button below will do this.</p>\r";
				echo "<p>Deactivating or removing this plugin does not remove settings and permissions from the database, so if you want to clean things up, this is the way to do it.</p>\r";
				echo "<p>It should really be understood that this is a last resort button. You will need to reset ALL permissions afterwords!</p>\r";
				echo "<input type='button' onclick='location.href=\"http" . ( isset($_SERVER['HTTPS'] ) && $_SERVER['HTTPS'] != 'off' ? "s" : "" ) . "://" . $_SERVER["SERVER_NAME"] . $_SERVER['REQUEST_URI'] . "&spDeleteItAll=1\"' name='simplePermissionsDeleteItAll' value='Delete It All'>";
			} else if ( isset( $_GET['spEditGroup'] ) ) {
				echo "<h2>Group Name</h2>\r";
				echo "<input type='text' style='width: 250px;' name='simplePermissionsNewGroupName' value='" . $devOptions['groups'][$_GET['spEditGroup']]['name'] . "'>\r";
				echo "<input type='hidden' name='simplePermissionsOldGroupName' value='" . ( $_GET['spEditGroup'] == 'new' ? '' : $devOptions['groups'][$_GET['spEditGroup']]['name'] ) . "'>\r";
				echo "<input type='hidden' name='simplePermissionsGroupID' value='" . ( $_GET['spEditGroup'] == 'new' ? $this->spGetNextGroupID() : $_GET['spEditGroup'] ) . "'>\r";

				echo "<h2>Members</h2>\r";
				echo "<p>One username per line.</p>\r";
				echo "<textarea rows=10 cols=25 spellcheck='false' name='simplePermissionsGroupMembers'>\r";
				if ( $_GET['spEditGroup'] != 'new' ) {
					$members = array();
					foreach ( $devOptions['groups'][$_GET['spEditGroup']]['members'] as $member ) {
						$wpUserData = get_userdata( $member );
						if ( ! $wpUserData === false ) {
							$members[] = $wpUserData->user_login;
						}
					}
					natcasesort( $members );
					foreach ( $members as $member ) {
						echo $member . "\r";
					}
				}
				echo "</textarea>\r";
				echo "<br><br>\r";

				//Category limiting
				//as of 1.1.0
				echo "<h2>Prevent posting in these categories</h2>\r";
				$this->spHierarchicalCategoryTree( 0, $devOptions['groups'][$_GET['spEditGroup']], 0 );
				echo "<br><br>\r";

				echo "<input type='submit' value='Save'>\r";
			} else if ( isset( $_GET['spDeleteGroup'] ) ) {
				echo "<h2>Confirm Group Delete</h2>\r";
				echo "<p>Clicking the button below will delete the group named \"" . $devOptions['groups'][$_GET['spDeleteGroup']]['name'] . "\". Are you sure you want to delete this group?</p>\r";
				echo "<input type='hidden' name='spDeleteGroupConfirmed' value='" . $_GET['spDeleteGroup'] . "'>\r";
				echo "<input type='submit' value='Delete'>\r";
			}
			?>
</form>
</div><?php
		} //End function spPrintAdminPage()

	} //End Class SimplePermissions

} //End if class exists

if ( class_exists( "SimplePermissions" ) ) {
	$svvsd_simplePermissions = new SimplePermissions();
}

//Initialize the admin panel
if ( ! function_exists( "spAddOptionPage" ) ) {
	function spAddOptionPage() {
		global $svvsd_simplePermissions;
		if ( ! isset( $svvsd_simplePermissions ) ) {
			return;
		}
		if ( function_exists( 'add_options_page' ) ) {
			add_options_page( 'Simple Permissions', 'Simple Permissions', 9, basename( __FILE__ ), array( &$svvsd_simplePermissions, 'spPrintAdminPage' ) );
		}
	}	
}

function spCompareByName( $a, $b ) {
	return strcmp( $a['name'], $b['name'] );
}

function spMDArraySearch( $array, $key, $value ) {
    if ( is_array( $array ) ) {
        foreach ( $array as $subarray ) {
			if ( $subarray[$key] == $value ) {
				return array_search( $subarray, $array );
			}
        }
		return true;
    } else {
		return false;
	}
}

function spDelArgFromURL ( $url, $in_arg ) {
	if ( ! is_array( $in_arg ) ) {
		$args = array( $in_arg );
	} else {
		$args = $in_arg;
	}

	foreach ( $args as $arg ) {
		$pos = strrpos( $url, "?" ); // get the position of the last ? in the url
		$query_string_parts = array();

		foreach ( explode( "&", substr( $url, $pos + 1 ) ) as $q ) {
			list( $key, $val ) = explode( "=", $q );
			if ( $key != $arg ) {
				// keep track of the parts that don't have arg3 as the key
				$query_string_parts[] = "$key=$val";
			}
		}

		// rebuild the url
		$url = substr( $url, 0, $pos + 1 ) . join( $query_string_parts, '&' );
	}

	if ( strrpos( $url, "?" ) == strlen( $url ) - 1 ) {
		$url = strstr( $url, '?', true );
	}
	return $url;
}

function spAddMetaBox() {
	global $svvsd_simplePermissions;
	$devOptions = $svvsd_simplePermissions->spGetAdminOptions();
	if ( ! isset( $devOptions['allowedRole'] ) ) {
		return;
	}
	$user = wp_get_current_user();
	if ( current_user_can( 'activate_plugins' ) ) {
		$user->roles[] = 'administrator';
	}
	if ( count( $user->roles ) == 1 ) {
		switch ( $user->roles[0] ) {
			case 'administrator':
			case 'editor':
				$user->roles[] = 'editor';
			case 'author':
				$user->roles[] = 'author';
			case 'contributor':
				$user->roles[] = 'contributor';
				break;
		}
	}
	//echo "<!-- " . print_r( $user->roles, true ) . " -->\r";
	if ( in_array( $devOptions['allowedRole'], (array) $user->roles ) ) {
		add_meta_box(
				'simplepermissions_meta_box'
				,__( 'Simple Permissions' )
				,'spRenderMetaBox'
				,get_post_type( get_the_ID() )
				,'normal'
				,'high'
			);
	}
}

function spRenderMetaBox( $post ) {
	global $svvsd_simplePermissions;
	$permissions = $svvsd_simplePermissions->spGetPermissions( $post->ID );
	$devOptions = $svvsd_simplePermissions->spGetAdminOptions();
	usort( $devOptions['groups'], "spCompareByName" );
	usort( $permissions, "spCompareByName" );
?>
	<input type='hidden' name='update_simplePermissionsForPost' value='1'>
	<script>
	function sp_handleCheckboxClick( cb ) {
		if ( cb.checked && cb.name.indexOf("write") != -1 ) {
				var readCheckboxID = cb.name.replace( "write", "read" );
				var readCheckbox = document.getElementById( readCheckboxID );
				if ( readCheckbox.checked === false ) {
					readCheckbox.checked = true;
				}
				var grpNum = cb.name.split("_")[2];
				if ( grpNum == 0 || grpNum == 1 ) {
					var readWarning = document.getElementById( "sp_readabilityWarning" );
					readWarning.style.display = 'block';
				}
		} else if ( ! cb.checked && cb.name.indexOf("read") != -1 ) {
			var writeCheckboxID = cb.name.replace( "read", "write" );
			var writeCheckbox = document.getElementById( writeCheckboxID );
			if ( writeCheckbox != null ) {
				if ( writeCheckbox.checked === true ) {
					writeCheckbox.checked = false;
				}
			}
			var grpNum = cb.name.split("_")[2];
			if ( grpNum == 0 || grpNum == 1 ) {
				var readWarning = document.getElementById( "sp_readabilityWarning" );
				readWarning.style.display = 'none';
			}
		} else if ( cb.checked && cb.name.indexOf("read") != -1 ) {
			var grpNum = cb.name.split("_")[2];
			if ( grpNum == 0 || grpNum == 1 ) {
				var readWarning = document.getElementById( "sp_readabilityWarning" );
				readWarning.style.display = 'block';
			}
		}
	}
	</script>
	<div id='sp_tableDiv' style='float: left;'>
	<table border=1 style='border-collapse: collapse; border: 1px solid gray; max-width: 400px;'>
	<thead style='background: lightgray;'>
		<tr><th style='padding: 3px;'>Group Name</th><th style='width: 44px;'>Read</th><th style='width: 46px;'>Write</th></tr>
	</thead>
	<tbody><?php
	$showReadabilityWarning = false;
	foreach ( $devOptions['groups'] as $group ) {
		$spMDArraySearchResult = spMDArraySearch( $permissions, 'id', $group['id'] );
		if ( ! is_bool( $spMDArraySearchResult ) ) {
			$permission = $permissions[$spMDArraySearchResult]['permission'];
			if ( $group['id'] == 0 || $group['id'] == 1 ) {
				$showReadabilityWarning = true;
			}
		} else {
			$permission = "";
		}
		if ( $group['id'] != 0 && $group['id'] != 1 ) {
			echo "\t\t<tr><td style='padding: 3px; max-width: 200px; word-break: break-all;'>" . $group['name'] . "</td><td><input type='checkbox' name='simplePermissions_grp_" . $group['id'] . "_read' id='simplePermissions_grp_" . $group['id'] . "_read' onclick='sp_handleCheckboxClick(this);'" . ( $permission == 'read' || $permission == 'write' ? " checked" : "" ) . " style='margin-left: 15px;'></td>";
			echo "<td><input type='checkbox' name='simplePermissions_grp_" . $group['id'] . "_write' id='simplePermissions_grp_" . $group['id'] . "_write' onclick='sp_handleCheckboxClick(this);' " . ( $permission == 'write' ? " checked" : "" ) . " style='margin-left: 15px;'></td></tr>\r";
		} else if ( $group['id'] == 1 ) {
			$loggedIn = "\t\t<tr><td style='padding: 3px; max-width: 200px; word-break: break-all;'>" . $group['name'] . "</td><td><input type='checkbox' name='simplePermissions_grp_" . $group['id'] . "_read' id='simplePermissions_grp_" . $group['id'] . "_read' onclick='sp_handleCheckboxClick(this);'" . ( $permission == 'read' || $permission == 'write' ? " checked" : "" ) . " style='margin-left: 15px;'></td>\r";
			$loggedIn .= "<td><input type='checkbox' name='simplePermissions_grp_" . $group['id'] . "_write' id='simplePermissions_grp_" . $group['id'] . "_write' onclick='sp_handleCheckboxClick(this);' " . ( $permission == 'write' ? " checked" : "" ) . " style='margin-left: 15px;'></td></tr>\r";
		} else if ( $group['id'] == 0 ) {
			$public = "\t\t<tr><td style='padding: 3px; max-width: 200px; word-break: break-all;'>" . $group['name'] . "</td><td><input type='checkbox' name='simplePermissions_grp_" . $group['id'] . "_read' id='simplePermissions_grp_" . $group['id'] . "_read' onclick='sp_handleCheckboxClick(this);'" . ( $permission == 'read' || $permission == 'write' ? " checked" : "" ) . " style='margin-left: 15px;'></td>\r";
			$public .= "<td><input type='checkbox' name='simplePermissions_grp_" . $group['id'] . "_write' id='simplePermissions_grp_" . $group['id'] . "_write' onclick='sp_handleCheckboxClick(this);' " . ( $permission == 'write' ? " checked" : "" ) . " style='margin-left: 15px;'></td></tr>\r";
		}
	}
	echo $loggedIn;
	echo $public;?>
	</tbody>
	</table>
	</div>
	<div id='sp_readabilityWarning' style='float: left; border: 1px solid black; background: lightgray; margin-left: 30px; width: 300px; display: <?php echo ( $showReadabilityWarning ? 'block' : 'none' ); ?>;'>
	<p style='text-align: center;'><strong>Attention:</strong></p>
	<p style='padding-left: 5px; padding-right: 5px;'>You have selected to make this document readable to "Public" and/or "Logged In Users". This will override any other groups ability or inability to read this document. Write permissions are NOT affected.</p>
	</div>
	<div style='clear: both; margin-bottom: -10px;'>&nbsp;</div><?php
	return true;
}

$is404Check = false;

//Actions and Filters
if ( isset( $svvsd_simplePermissions ) ) {
	//Filters
	add_filter( 'plugin_action_links_' . plugin_basename( plugin_dir_path( __FILE__ ) . 'simple-permissions.php' ), array( &$svvsd_simplePermissions, 'spSettingsLink' ) );
	add_filter( 'user_has_cap', array( &$svvsd_simplePermissions, 'spUserCanDo' ), 99, 3 ); // priority 99 means it goes last-ish
	add_filter( 'posts_join', array( &$svvsd_simplePermissions, 'spCustomJoin' ) );
	add_filter( 'posts_where', array( &$svvsd_simplePermissions, 'spCustomWhere' ) );
	add_filter( 'posts_distinct', array ( &$svvsd_simplePermissions, 'spSearchDistinct' ) );
	add_filter( 'template_redirect', array ( &$svvsd_simplePermissions, 'spOverride404' ) );
	add_filter( 'list_terms_exclusions', array ( &$svvsd_simplePermissions, 'spExcludeCategories' ), 10, 2 );

	//Actions
	add_action( 'admin_menu', 'spAddOptionPage' );
	add_action( 'activate_simplePermissions/simple-permissions.php',  array( &$svvsd_simplePermissions, '__construct' ) );
	add_action( 'add_meta_boxes', 'spAddMetaBox' );
	add_action( 'save_post', array( &$svvsd_simplePermissions, 'spUpdatePost' ) );
}
?>