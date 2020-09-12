Riverslice - Channel hydraulic geometry calculation tool
========================================================

This is a PHP application to create an online channel hydraulic geometry calculation tool.


Screenshot
----------

![Screenshot](screenshot.png)


Usage
-----

1. Clone the repository.
2. Download the library dependencies and ensure they are in your PHP include_path.
3. Add the Apache directives in httpd.conf (and restart the webserver) as per the example given in .httpd.conf.extract.txt; the example assumes mod_macro but this can be easily removed.
4. Create a copy of the index.html.template file as index.html, and fill in the parameters.
5. Access the page in a browser at a URL which is served by the webserver.


Dependencies
------------

* [application.php application support library](https://download.geog.cam.ac.uk/projects/application/)
* [frontControllerApplication.php front controller application implementation library](https://download.geog.cam.ac.uk/projects/frontcontrollerapplication/)
* [pureContent.php general environment library](https://download.geog.cam.ac.uk/projects/purecontent/)


Author
------

Formulae derived by Renée Kidson 2003-4.  
Coding by Martin Lucas-Smith, Department of Geography, University of Cambridge, 2003-2010, 2020.


License
-------

GPL3.

