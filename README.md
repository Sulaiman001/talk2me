weight-tracker
==============

A simple weight tracker for marking meals or snacks throughout the day.

Dependencies
============

* HTML, CSS, JavaScript
* PHP
* MongoDB

Configuration
=============

All data is stored in a mongo database. Host and database should be set in `ajax.php` towards the top of the file.

To add or remove food items just add or remove the buttons in `index.html`.

e.g. `<button data-type="snoopy-one" type="button" class="btn btn-primary btn-md">snoopy</button>`

The `data-type` attributes must be unique. They must also have at least the class `btn`.

Screenshot
==========

<a target="_blank" href="screen.png"><img src="screen.png" alt="screenshot" /></a>
