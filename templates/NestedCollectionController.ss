<div class="typography">
	<div id="Breadcrumbs">
	   	<p>$Breadcrumbs</p>
	</div>
<% if Form %>
<h2>Create new $SingularName</h2>
$Form
<% else %>
<h2>View $PluralName</h2>
<% if AllRecords %>
<ul><% control AllRecords %>
	<li><a href="{$Top.Link}$ID" title="View $Name">$Name</a></li>
<% end_control %></ul>
  <% if AllRecords.MoreThanOnePage %>
  <p>
    <% if AllRecords.PrevLink %><a href="$AllRecords.PrevLink">&lt;&lt; Previous</a> | <% end_if %>
    <% control AllRecords.Pages %>
      <% if CurrentBool %><strong>$PageNum</strong><% else %><a href="$Link" title="Go to page $PageNum">$PageNum</a> <% end_if %>
    <% end_control %>
    <% if AllRecords.NextLink %>| <a href="$AllRecords.NextLink">Next &gt;&gt;</a><% end_if %>
  </p>
  <% end_if %>
<% else %>
<p><em>No $PluralName found</em></p>
<% end_if %>
<% if canCreate %>
<p><a href="{$Link}create-new" title="Create new $SingularName">Create new $SingularName</a></p>
<% end_if %>
<% end_if %>
</div>