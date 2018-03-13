NESTED CONTROLLERS SNIPPET FOR SILVERSTRIPE

About
=====

This isn't really a module, but I've re-used it with many projects now so I figured it deserved to be shared.
It enables you to create a logical url structure for actions on records (DataObjects).

For example, it can be used for CRUD (Create Read Update Delete), or to step through required processes of creating an object in a logical way

eg: 
`/mypage/people/12/edit
/mypage/people/12/favourite-people`

It allows deep traversal through related objects:

eg: `/mypage/people/12/mother/uncles/43/edit`

And it allows for easy theme overrides. [Record]_[function].ss templates are chosen ahead of [Record].ss templates. And there are fallback templates to kick you off.

How to use
==========

As a starting point, you need a page that can call the nested functions. eg

	<?php
	class MyPage extends Page {
		//...
	}
	class MyPage_Controller extends Page_Controller {
		//..
		public function people($request) {
			return new PeopleCollectionController($this, $request);
		}
	}

This means that whenever anyone calls a MyPage/people, the `PeopleCollectionController` will be used.

Obviously you need a `PeopleCollectionController` so lets have a look at what it might look like.

	<?php
	class PeopleCollectionCotroller extends NestedCollectionController {
		public function favourite_people() {
			// return something allowing the user to view or modify favourite people
		}
	}


