<?php
// This file is part of BOINC.
// http://boinc.berkeley.edu
// Copyright (C) 2008 University of California
//
// BOINC is free software; you can redistribute it and/or modify it
// under the terms of the GNU Lesser General Public License
// as published by the Free Software Foundation,
// either version 3 of the License, or (at your option) any later version.
//
// BOINC is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.
// See the GNU Lesser General Public License for more details.
//
// You should have received a copy of the GNU Lesser General Public License
// along with BOINC.  If not, see <http://www.gnu.org/licenses/>.

require_once("../inc/util_ops.inc");
require_once("../inc/forum.inc");
require_once("../inc/profile.inc");

db_init();

/***********************************************************************\
 * Action: Process form info & controls
\***********************************************************************/

$limit = get_int('limit', true);
if (! $limit > 0 ) $limit = 30;


/***********************************************************************\
 * Delete user (copied from manage_user.php)
\***********************************************************************/
if (isset($_POST['delete_user'])) {
    $id = post_int("userid", true);
    if (!$id) admin_error_page("No ID given");
    $user = BoincUser::lookup_id($id);
    if (!$user) admin_error_page("No such user: $id");
    possibly_delete_user($user);
}

// Delete a user if they have no credit, results, or posts
//
function possibly_delete_user($user){
    if ($user->total_credit > 0.0){
        admin_error_page("Cannot delete user: User has credit.");
    }

    // Don't delete user if they have any outstanding Results
    //
    if (BoincResult::count("userid=$user->id")) {
        admin_error_page("Cannot delete user: User has count results in the database.");
    }

    // Don't delete user if they have posted to the forums
    //
    if (BoincPost::count("user=$user->id")) {
        admin_error_page("Cannot delete user: User has forum posts.");
    }

    if ($user->teamid){
        user_quit_team($user);
    }
    delete_user($user);
}

/***********************************************************************\
 * Whitelists of users and teams: do not display them in the list
\***********************************************************************/

$whitelist_users = "'1', '2'";
$whitelist_teams = "'1', '2'";

/***********************************************************************\
 * Display the page:
\***********************************************************************/

admin_page_head("Suspicious Users");

echo "<br/>";
echo "These are the ".$limit." <b>suspicious</b> users.<br>\n";
echo "Clicking on a name opens a user management page <i>in another window or tab</i>\n";

echo "<form name=\"new_user_limit\" action=\"?\" method=\"GET\">\n";
echo "<label for=\"limit\">Limit displayed users to</label>\n";
echo "<input type=\"text\" value=\"".$limit."\" name=\"limit\" id=\"limit\" size=\"5\">";
echo "<input class=\"btn btn-default\" type=\"submit\" value=\"Display\">\n";
echo "</form>\n";


/***********************************************************************\
 * Form SELECT query (copied from delete_spammers.php):
\***********************************************************************/

$query  = " SELECT a.* FROM user a ";
$query .= " LEFT JOIN host c ON c.userid=a.id ";
$query .= " LEFT JOIN post b ON a.id=b.user ";
//$query .= " LEFT JOIN team d ON a.id=d.userid ";
$query .= " WHERE true ";
$query .= " AND c.userid IS null ";
$query .= " AND b.user IS null ";
//$query .= " AND d.userid IS null ";

// Do not display whitelist users/team members to avoid their accidental deletion
$query .= " AND a.id NOT IN (".$whitelist_users.") ";
$query .= " AND a.teamid NOT IN (".$whitelist_teams.") ";

$query .= " ORDER BY has_profile DESC, teamid DESC, create_time DESC LIMIT $limit";

$result = _mysql_query($query);
if (_mysql_num_rows($result) < 1) {
    echo "There are no new users.";
    admin_page_tail();
}

start_table();
table_header("ID", "<img width=\"20\" src=\"img/Attention.png\"></img>", "Name", "Email", "Team", "Founder", "Profile", "Joined");

while ($row = _mysql_fetch_object($result)) { 
    $id = $row->id;
    $name = $row->name;
    $email = $row->email_addr;
    $country = $row->country; 
    $joined = time_str($row->create_time);
    $email_validated = $row->email_validated;
    
    $team_name="";
    $team_id = $row->teamid;
    if($team_id > 0){
        $team = BoincTeam::lookup_id($team_id);
        if($team != NULL)
          $team_name = $team->name;
    }
    
    // Special Users:
    $roles = "";
    $user = $row;
    BoincForumPrefs::lookup($user);
    $special_bits = $user->prefs->special_user;
    if ($special_bits != "0") {
        for ($i = 0; $i < 7; $i++) {
            $bit = substr($special_bits, $i, 1);
            if ($bit == '1'){
                if (!empty($roles)) {
                    $roles .= ", ";
                }
                $roles .= $special_user_bitfield[$i];
            }
        }
    }
    if (!empty($roles)) {
        $roles = "<small>[$roles]</small>";
    }
    
    // Banished?
    if (!empty($user->banished_until)) {
        $dt = $user->banished_until - time();
        if( $dt > 0 ) {
            $x = "<span style=\"color: #ff0000\">Currently banished</span>";
        }
        else {
            $x = "<span style=\"color: #ff9900\">Previously banished</span>";
        }
        $roles .= $x;
    }
    
    if ($email_validated) {
        $email = "<span style=\"color: #ffff00\">".$email."</span>\n";
    } else {
        $email = "<span style=\"color: #ff0000\">".$email."</span>\n";
    }

    if($team_id > 0) {
      if($team_name != "") 
        $team_display = "<a href=\"db_action.php?table=team&clauses=id%3D".$team_id."\">".$team_name."</a>";
      else 
        $team_display = "(deleted)";
    }
    else $team_display = "";
 
    $founder_display = "";
    $teams_founded = BoincTeam::enum("userid=$user->id");
        foreach ($teams_founded as $team) {
          $founder_display .= "<a href=\"db_action.php?table=team&clauses=id%3D".$team->id."\">".$team->name." (".$team->nusers." members)</a><br/>";
    }

    if ($user->has_profile) 
      $profile_display = "<a href=\"".url_base()."view_profile.php?userid=$user->id\" target=\"blank\">View</a>";
    else     
      $profile_display = "";

    table_row_colored($id, "<form name='manage_user' action=list_suspicious_users.php?limit=".$limit." method='POST'>
        <input type='hidden' name='userid' value='". $id."'> 
        <input class=\"btn btn-danger\" name=\"delete_user\" type=\"submit\" value=\"Delete user\"></form>",
        "<a href=\"manage_user.php?userid=".$id."\" target=\"blank\">".$name."</a> ".$roles, $email,
        $team_display, $founder_display, $profile_display, $joined);
}
end_table();

admin_page_tail();

$cvs_version_tracker[]="\$Id$";  //Generated automatically - do not edit
?>
