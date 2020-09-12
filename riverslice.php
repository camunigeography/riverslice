<?php

# Class to create an online River Slice - Channel Hydraulic Geometry calculation tool
require_once ('frontControllerApplication.php');
class riverslice extends frontControllerApplication
{
	# Function to assign defaults additional to the general application defaults
	public function defaults ()
	{
		# Specify available arguments as defaults or as NULL (to represent a required argument)
		$defaults = array (
			'applicationName'		=> 'River Slice: Channel Hydraulic Geometry',
			'div'					=> strtolower (__CLASS__),
			'tabUlClass'			=> 'tabsflat',
			'useDatabase'			=> false,
			'roundTo'				=> 3,
		);
		
		# Return the defaults
		return $defaults;
	}
	
	
	# Function to assign supported actions
	public function actions ()
	{
		# Define available actions
		$actions = array (
			'about' => array (
				'description' => 'About this facility',
				'url' => 'about.html',
				'tab' => 'About this facility',
			),
		);
		
		# Return the actions
		return $actions;
	}
	
	
	# Home page
	public function home ()
	{
		# Export to Excel if requested; this function must come before the remaining code because it involves sending HTTP Headers
		if (isSet ($_GET['export'])) {
			
			# Ensure the HTML variable is set
			if (isSet ($_POST['html'])) {$html = $_POST['html'];} else {$html = '';}
			
			# Export to the required format then end
			$this->export ($html);
			return;
		}
		
		if (!isSet ($_POST['discharge'])) {
			
			echo "\n<p>This facility allows you interactively to calculate the basic hydraulic geometry properties of a river cross section.</p>";
			
			# Define the form for the main part of the program
			$form = $this->defineInitialInputForm ();
			if ($data = $form->process ()) {
				
				# Rearrange the data
				$data = $this->alterArrayArrangement ($data);
				
				# Select the appropriate calculation to run
				(($data['function'] == 'Area calculation from Stage(s)')
					? $this->calculateAreaFromStage ($data, $this->settings['roundTo'])
					: $this->calculateStageFromArea ($data, $this->settings['roundTo']));
			}
			
		} else {
			
			# Run the discharge stage of the program
			$this->dischargeStage ($this->settimgs['roundTo']);
		}
	}
	
	
	# Function to create the initial input form
	private function defineInitialInputForm ()
	{
		# Create the form
		$form = new form (array (
			'name' => 'main',
			'displayRestrictions' => false,
			'displayDescriptions' => true,
			'displayColons' => true,
			'submitButtonText' => 'Submit your data!',
			'formCompleteText' => false,
			'unsavedDataProtection' => true,
		));
		$form->input (array (
			'name'			=> 'name',
			'title'					=> 'Name',
			'description'	=> 'A name for your cross-section.',
			'required'				=> true,
			'size'					=> 32,
			'maxlength'				=> 128,
		));
		$form->radiobuttons (array (
			'name'			=> 'units',
			'values'			=> array ('Metric (metres)', 'Imperial/US Customary (feet)',),
			'title'					=> 'Units',
			'description'	=> 'The units in which the data being entered is measured.',
			'required'				=> true,
		));
		$form->textarea (array (
			'name'			=> 'data',
			'title'					=> 'Data',
			'description'	=> 'Copy and paste in your cross section geometry data, formatted as:<br />
				<ul>
					<li>One x,y co-ordinate pair per line</li>
					<li>Space(s)/tab(s) between the x and y on the line</li>
					<li>Numeric characters only: alphabetical characters will be removed</li>
					<li>Data beyond the first two columns will be removed</li>
				</ul>',
			'required'				=> true,
			'enforceNumeric'		=> true,
			'cols'				=> 40,
			'rows'					=> 20,
			'mode'					=> 'coordinates',
		));
		$form->radiobuttons (array (
			'name'			=> 'function',
			'values'			=> array ('Area calculation from Stage(s)', 'Stage calculation from Area',),
			'title'					=> 'Calculate',
			'description'	=> 'Select which you wish to calculate.',
			'required'				=> true,
		));
		$form->textarea (array (
			'name'			=> 'stageorarea',
			'title'					=> 'Stage(s) or area',
			'description'	=> 'Enter either the stage(s) or the area, one per line, using the same units as above.',
			'required'				=> true,
			'enforceNumeric'		=> true,
			'cols'				=> 40,
			'rows'					=> 7,
			'mode'					=> 'lines',
		));
		$form->input (array (
			'name'			=> 'overbank',
			'title'					=> 'Overbank analysis <strong>(optional)</strong>',
			'description'	=> 'If you wish to produce results detailing the proportion of flow components in overbank areas, enter the elevation at which spillage from the main channel onto overbank areas is initiated. (This can occur from either side of the channel.)',
			'required'				=> false,
			'enforceNumeric'		=> true,
			'size'					=> 20,
			'maxlength'				=> 128,
		));
		
		# Return the form
		return $form;
	}
	
	
	# Function to alter the arrangement of the array
	private function alterArrayArrangement ($data)
	{
		# Add an array of x and y co-ordinates into the data for future use then destroy the original
		for ($i = 0; $i < count ($data['data']); $i++) {
			$data['x'][$i] = $data['data'][$i]['x'];
			$data['y'][$i] = $data['data'][$i]['y'];
		}
		unset ($data['data']);
		
		# Sort the user's x and y into ascending order by x, maintaining their associations, in case they have entered it wrongly
		array_multisort ($data['x'], $data['y']);
		
		# Return the re-arranged data
		return $data;
	}
	
	
	# Wrapper function to calculate 'Stage calculation from Area'
	private function calculateAreaFromStage ($data, $roundTo)
	{
		//# Show the data that the user has submitted
		//$this->showData ($data);
		//application::dumpData ($data);
		
		# Strip non-numeric characters from $data['overbank'] if that has been entered
		$data['overbank'] = preg_replace ("/[^-0-9\.\n\t ]/", '', $data['overbank']);
		
		# If a (cleaned) overbank figure has been entered, then calculate the two interface points for this
		$interfaceError = false;
		$interface = '';
		
		# If doing the overbank analysis, calculate the interface points for the stage entered as $data['overbank']
		if ($data['overbank'] != '') {
			list ($data, $interface, $interfaceError) = $this->calculateInterfacePoints ($data);
		}
		
		# Proceed if no interface error has occurred
		if (!$interfaceError) {
			
			# Sort the stage list if the user has not entered these in order
			$data['stageorarea'] = array_unique ($data['stageorarea']);
			sort ($data['stageorarea']);
			
			# Check that the stage(s) are (all) are above the minimum y value, or stop
			foreach ($data['stageorarea'] as $stage) {
				if (($stage - min ($data['y'])) <= 0) {
					$stageError = $this->throwError (1);
					break;
				}
			}
			#!# This needs to revert to a warning and continue for the stages which are correct
			if (!isSet ($stageError)) {
				
				# Produce a warning (not a 'stop' error) if the stage(s) do not intercept the channel geometry
				foreach ($data['stageorarea'] as $stage) {
					if (($stage - max ($data['y'])) > 0) {
						$warning = $this->throwError (5);
						#!# Ideally, here state which stages and change the sentence accordingly
						break;
					}
				}
				
				# Obtain the $result by calculating the data for each stage (even if only one stage)
				$i = 0;
				foreach ($data['stageorarea'] as $stage) {
					# Obtain for each $result[$stage] the array ($stage, $totalArea, $totalWettedPerimeter, $hydraulicRadius, $columnArea, $wettedPerimeter)
					$result[$i] = $this->calculateArea ($data, $stage);
					$i++;
				}
				
				# Remove null results and reset the array keys
				$stages = count ($result);
				for ($stage = 0; $stage < $stages; $stage++) {
					if ($result[$stage] == '') {
						unset ($result[$stage]);
					}
				}
				$result = array_merge (array(), $result);
				
				# Display the result, unless no results exist
				if (!$result) {
					$this->throwError (4);
				} else {
					
					# Finally, create the result table based on the $result data, including constructing the bridge form
					list ($result, $componentKeys) = $this->createResultTable ($result, $data, $interface, $roundTo);
					
					#!# Need to shove this section into a separate function
					# Shunt the $result field through the form bridge as a serialised array
					$carriedData['hidden']['result'] = urlencode (serialize ($result));
					
					# Shunt the keys through the form bridge as a serialised array
					$i = 0;
					foreach ($componentKeys as $componentKey) {
						$keys[$i] = $data['x'][$componentKey];
						$i++;
					}
					#!# Very nasty hack: get the next component key so that the form can present 'something to something' for last item (which would otherwise just be 'something to '
					$keys[$i] = $data['x'][$componentKey + 1];
					$carriedData['hidden']['keys'] = urlencode (serialize ($keys));
					
					# Show the a form to bridge to the discharge form, unless they are using imperial measurements, in which case deny this option
					if ($result[0]['units'] == 'Metric (metres)') {
						$form = $this->defineDischargeCalculationForm ($carriedData, '', $roundTo);
						$form->process ();
					} else {
						echo "\n<p>Discharge calculation is available with this facility for metric data only.</p>";
					}
				}
			}
		}
	}
	
	# Wrapper function to calculate 'Area calculation from Stage(s)'
	private function calculateStageFromArea ($data, $roundTo)
	{
		# Show the data that the user has submitted
		//$this->showData ($data);
		//application::dumpData ($data);
		
		# Work out which units have been used
		$units = (($data['units'] == 'Metric (metres)') ? 'metres' : 'feet');
		
		# Check that the 
		if ($data['stageorarea'][0] <= 0) {
			$error = $this->throwError(11);
		} else {
			
			# Produce a warning (not a 'stop' error) if more than one area has been entered
			if (isSet ($data['stageorarea'][1])) {
				$error = $this->throwError(7);
			}
			
			# Assign a stage start point at 2.5m above user's lowest y value
			$stage = min($data['y']) + 2.5;
			
			# Calculate the area based on the stage start point just obtained
			$result = $this->calculateArea ($data, $stage);
			
			# Calculate the initial error margin
			$errorMargin = $data['stageorarea'][0] / $result['totalArea'];
			
			# Establish an iteration limit to prevent potential overload
			$iterationLimit = 50;
			
			# Iterate creating the result until the error margin is sufficiently small
			$iteration = 0;
			while ((($errorMargin < 0.99) || ($errorMargin > 1.01)) && (++$iteration <= $iterationLimit)) {
				
				# Calculate the new stage
				$stage = min($data['y']) + (($stage - min($data['y'])) * $errorMargin);
				
				# Calculate the new area
				if ($temporaryResult = $this->calculateArea ($data, $stage)) {
					
					# Assign this temporary result as the real result to be retained
					$result = $temporaryResult;
					
					# Calculate the error margin
					$errorMargin = $data['stageorarea'][0] / $result['totalArea'];
				} else {
					
					# Exit the while loop if the result is false (i.e. error 4 has been thrown internally)
					break;
				}
			}
			
			# Prepare the error margin for presentation
			$errorMarginPresented = (($result['totalArea'] - $data['stageorarea'][0]) / $data['stageorarea'][0]) * 100;
			
			# Show the number of iterations required (debugging)
			#echo '<p>' . ($iteration - 1) . ' iteration(s) were required.</p>';
			
			# Show the result
			echo '<p>The optimal stage for this analysis is: <strong>' . number_format(round($stage, $roundTo), 3) . '</strong> ' . $units = (($data['units'] == "Metric (metres)") ? "metres" : "feet") . '.</p>';
			echo '<p>The error margin for this calculation is: ' . number_format(round($errorMarginPresented, $roundTo), 3) . '%.</p>';
		}
	}
	
	
	# Function to calculate the interface points for the overbank analysis
	private function calculateInterfacePoints ($data)
	{
		# Start a flag determining whether there is a problem with the interface points
		$interfaceError = false;
		
		# Start a tally of the number of pairs of interface points
		$interfacePointsPairs = 0;
		
		# Count the number of data points
		$total = count ($data['x']);
		
		# Go through each data point and get the interface point if it lies there
		for ($i = 0; $i < $total; $i++) {
			if (isSet ($data['y'][$i + 1])) {
				if (($data['y'][$i] > $data['overbank']) && ($data['y'][$i + 1] > $data['overbank'])) {
				} else {
					if ($data['overbank'] < max ($data['y'][$i], $data['y'][$i + 1])) {
						
						# Create variables containing useful figures which are then used in the calculations
						list ($x, $y) = $this->getMaxMinDifference ($data, $i);
						
						# Calculate the two interface points
						$data['overbank'] . " - " . $data['y'][$i] . "<br />";
						if (($data['overbank'] - $data['y'][$i]) < 0) {
							# Get the left interface; if there is more than one, then take the first only (i.e. the leftmost interface point in a compound channel)
							if (!isSet ($interface['left'])) {
								$interface['left'] = $x['max'] - ($x['difference'] * ($data['overbank'] - $y['min']) / $y['difference']);
							}
						} else if (($data['overbank'] - $data['y'][$i]) > 0) {
							# Get the right interface; if there is more than one, then replace the previous one found (i.e. end up with the rightmost interface point in a compound channel)
							$interface['right'] = $x['min'] + ($x['difference'] * ($data['overbank'] - $y['min']) / $y['difference']);
							# Increment the number of pairs of interface points after a right-side interface has been found
							$interfacePointsPairs++;
						}
					}
				}
			}
		}
		
		# If an overbank figure has been entered but it has found no interface points then the threshold is wrong
		if ((!isSet ($interface['left'])) || (!isSet ($interface['right']))) {
			$interface['left'] = '';
			$interface['right'] = '';
			$this->throwError (6);
			$interfaceError = true;
		} else {
			# Push these values into the data, unless there is a duplicate point; the check for whether there is duplicate point needs to be run twice - one for each side
			$thresholdIsDuplicated = false;
			$total = count ($data['x']);
			for ($i = 0; $i < $total; $i++) {
				if (($data['x'][$i] == $interface['left']) && ($data['y'][$i] == $data['overbank'])) {
					$thresholdIsDuplicated = true;
				}
			}
			if (!$thresholdIsDuplicated) {
				array_push ($data['x'], $interface['left']);
				array_push ($data['y'], $data['overbank']);
			}
			
			$thresholdIsDuplicated = false;
			for ($i = 0; $i < $total; $i++) {
				if (($data['x'][$i] == $interface['right']) && ($data['y'][$i] == $data['overbank'])) {
					$thresholdIsDuplicated = true;
				}
			}
			if (!$thresholdIsDuplicated) {
				array_push ($data['x'], $interface['right']);
				array_push ($data['y'], $data['overbank']);
			}
			
			# Sort the user's x and y into ascending order by x, maintaining their associations, so as to include the newly-found values in the correct order
			array_multisort ($data['x'], $data['y']);
		}
		
		# Return the data
		return array ($data, $interface, $interfaceError);
	}
	
	
	# Function to show on screen the user's submitted data
	private function showData ($data)
	{
		# Start to display the submitted data
		$html = '<p>You submitted the following data, in <strong>' . (($data['units'] == "Metric (metres)") ? "metres" : "feet") . '</strong>:</p>';
		
		# Create a table of the data, enclosed by a larger table in case it needs to be broken into subtables due to large amounts of data
		$html .= "\n<table class=\"noborder\">\n\t<tr>\n\t\t<td valign=\"top\">";
		$html .= "\n\t\t\t<table>";
		
		# Add the headings "x" and "y"
		$html .= "\n\t\t\t\t<tr>\n\t\t\t\t\t<th>x</th>\n\t\t\t\t\t<th>y</th>\n\t\t\t\t</tr>";
		
		# Go through each set of x,y co-ordinates
		$total = count ($data['x']);
		for ($i = 0; $i < $total; $i++) {
			$html .= "\n\t\t\t\t<tr>\n\t\t\t\t\t<td>" . $data['x'][$i] . "</td>\n\t\t\t\t\t<td>" . $data['y'][$i] . "</td>\n\t\t\t\t</tr>";
			
			# If there are lots of columns, break the presentation into a series of smaller tables
			$breakAt = 10;
			if ((($i + 1)/$breakAt) == round (($i + 1)/$breakAt)) {$html .= "\n\t\t\t</table>\n\t\t\t\n\t\t</td>\n\t\t<td valign=\"top\"><table class=\"border\">";}
		}
		$html .= "\n\t\t\t</table>";
		$html .= "\n\t\t</td>\n\t<tr>\n</table>";
		
		# Show the HTML
		echo $html;
	}
	
	
	# Function actually to calculate the area
	private function calculateArea ($data, $stage)
	{
		# Go through each y value until the stage is between the two points for the first time
		$crossSections = 0;
		$totalPoints = count ($data['x']);
		for ($i = 0; $i < $totalPoints; $i++) {
			
			# Check the logic of the status of the two points being checked
			if (isSet ($data['y'][$i + 1])) {
				
				# Check through each of the cross-sections to see if the stage lies between the two
				if (($stage == max ($data['y'][$i], $data['y'][$i + 1]))) {
					# It's the same, therefore it intersects; increment the cross-section tally
					$crossSections++;
					$yStatus[$i] = "intersecting";
				} elseif (($stage < max ($data['y'][$i], $data['y'][$i + 1])) && ($stage > min ($data['y'][$i], $data['y'][$i + 1]))) {
					# Otherwise it's between, therefore it intersects; increment the cross-section tally
					$crossSections++;
					$yStatus[$i] = "intersecting";
				} elseif (($stage > $data['y'][$i]) && ($stage > $data['y'][$i + 1])) {
					# Both are below (so don't increment the cross-section tally)
					$yStatus[$i] = "below";
				} else {
					# Otherwise it's above the stage (so don't increment the cross-section tally)
					$yStatus[$i] = "disregard";
				}
			}
		}
		
		# Having checked the status of the points, now perform the calculations
		for ($i = 0; $i < $totalPoints; $i++) {
			if (isSet ($data['y'][$i + 1])) {
				
				# Create variables containing useful figures which are then used in the calculations
				list ($x, $y) = $this->getMaxMinDifference ($data, $i);
				
				# Run calculations dependent on the status of the point being considered
				switch ($yStatus[$i]) {
					case "intersecting":
						# It's between, so this section should only be run twice
						
						# Algorithmic method to find the interface of the line with the stage(s)
						if ($y['difference'] == 0) {
							$distanceToNext = 0;	// Prevent division-by-zero errors
						} else {
							$distanceToNext = (($stage - $y['min']) * ($x['difference']  / $y['difference']));
						}
						
						# Calculate the triangle area for this section
						$columnArea[$i] = 0.5 * $distanceToNext * ($stage - $y['min']);
						
						# Calculate the wetted perimeter for this section
						$wettedPerimeter[$i] = pow((pow(($stage - $y['min']), 2) + pow($distanceToNext, 2)), 0.5);
						
						# Calculate the width and depth
						$width[$i] = $distanceToNext;
						$depth[$i] = 0.5 * ($stage - $y['min']);
						
						break;
					case "below";
						# Algorithmic method to find the area of the column between the two x values
						$areaRectangle[$i] = (($stage - $y['max']) * $x['difference']);
						$areaTriange[$i] = (0.5 * $x['difference'] * $y['difference']);
						$columnArea[$i] = $areaRectangle[$i] + $areaTriange[$i];
						
						# Algorithmic method to find the wetted perimeter distance between the two points
						$wettedPerimeter[$i] = pow(((pow($x['difference'], 2)) + (pow($y['difference'], 2))), 0.5);
						
						# Calculate the width and depth
						$width[$i] = $x['difference'];
						$depth[$i] = 0.5 * (($stage - $data['y'][$i]) + ($stage - $data['y'][$i + 1]));
						
						break;
					case "disregard":
						# Do nothing;
						break;
				}
			}
		}
		
		## Now we have all the results so the results can be assembled and displayed
		
		# Calculate the main values
		$totalArea = array_sum ($columnArea);
		$totalWettedPerimeter = array_sum ($wettedPerimeter);
		$hydraulicRadius = $totalArea / $totalWettedPerimeter;
		$totalWidth = array_sum ($width);
		$maximumDepth = max ($depth);
		
		# Return the result as an associative array
		return $result = array (
			'stage' => $stage,
			'units' => $data['units'],
			'totalArea' => $totalArea,
			'totalWettedPerimeter' => $totalWettedPerimeter,
			'hydraulicRadius' => $hydraulicRadius,
			'columnArea' => $columnArea,
			'wettedPerimeter' => $wettedPerimeter,
			'width' => $width,
			'totalWidth' => $totalWidth,
			'depth' => $depth,
			'maximumDepth' => $maximumDepth,
		);
	}
	
	
	# Function to get the maximum/minimum/difference
	private function getMaxMinDifference ($data, $i)
	{
		$x['max'] =  max ($data['x'][$i], $data['x'][$i + 1]);
		$x['min'] =  min ($data['x'][$i], $data['x'][$i + 1]);
		$x['difference'] = abs($data['x'][$i + 1] - $data['x'][$i]);
		$y['max'] =  max ($data['y'][$i], $data['y'][$i + 1]);
		$y['min'] =  min ($data['y'][$i], $data['y'][$i + 1]);
		$y['difference'] = abs($data['y'][$i + 1] - $data['y'][$i]);
		
		# Return the result
		return array ($x, $y);
	}
	
	
	# Function to create the result table in HTML based on the $result data
	private function createResultTable ($result, $data, $interface, $roundTo)
	{
		# Count the number of stages contained in the data (which has null results already removed)
		$stages = count ($result);
		
		# Obtain the key names contained in the data
		for ($stage = 0; $stage < $stages; $stage++) {
			# Add a list of the keys in an array to a master array $componentKeys
			$temporaryArray = array_keys($result[$stage]['columnArea']);
			if (isSet ($componentKeys)) {
				$componentKeys = array_merge ($componentKeys, $temporaryArray);
			} else {
				$componentKeys = $temporaryArray;
			}
		}
		
		# Unique the $componentKeys array and sort it
		$componentKeys = array_unique ($componentKeys);
		sort ($componentKeys);
		
		# Count the number of components
		$components = count($componentKeys);
		
		# Define the unit description
		$unitDescription = (($result[0]['units'] == 'Metric (metres)') ? 'm' : 'f');
		
		# Prepare to split the data into three channels or leave it as one if no overbank figure has been entered
		$channels = (($data['overbank'] != '') ? (3 + 1) : 1);
		
		# Define whether there should be a column "x="
		$showColumnX = true;
		
		# Pregenerate the individual component rows for use in a moment
		list ($componentsHtml, $tally, $componentsSplit) = $this->pregenerateIndividualComponents ($components, $channels, $data, $componentKeys, $interface, $result, $roundTo, $stages, $showColumnX, $unitDescription);
		
		# Begin introductory text and start the table, holding all in $html
		$introductoryHtml = "\n\n<ul>\n\t<li><a href=\"" . str_replace ('/index.html', '/', $_SERVER['REQUEST_URI']) . "\">Analyse a new set of data</a></li>\n</ul>";
		$introductoryHtml .= "\n\n<h2>Results for area calculation for cross section <em>" . htmlspecialchars ($data['name']) . "</em></h2>";
		$introductoryHtml .= "\n\n<p><em>Numbers are rounded to $roundTo decimal places.</em></p>";
		if ($result[0]['units'] == 'Metric (metres)') {
			$introductoryHtml .= "\n\n<ul>\n\t<li><a href=\"#discharge\"><strong>Use these results for calculating the discharge</strong></a></li>\n\n</ul>";
		}
		$html = "\n\n<table>";
		
		# First row containing titles (table headers), listing the stages
		$html .= "\n\t<tr>";
		$html .= "\n\t\t<th>&nbsp;</th>";
		$html .= ($showColumnX ? "\n\t\t<th class=\"comment\">x co-ordinate =</th>" : '');
		for ($stage = 0; $stage < $stages; $stage++) {
			$html .= "\n\t\t<th" . (($channels > 1) ? " colspan=\"$channels\"" : '') . ">At stage " . $result[$stage]['stage'] . "</th>";
		}
		$html .= "\n\t</tr>";
		
		# Extra inserted row if overbank analysis is being done (i.e. there are three channels plus a total); show the appropriate column headings in this case
		if (($data['overbank'] != '') && ($channels > 1)) {
			$html .= "\n\t<tr>";
			$html .= "\n\t\t<td class=\"title\"" . ($showColumnX ? ' colspan="2"' : '') . "><em>Channel</em></td>";
			for ($stage = 0; $stage < $stages; $stage++) {
				$html .= "\n\t\t<td class=\"results\">Left overbank</td>";
				$html .= "\n\t\t<td class=\"results\">Main channel</td>";
				$html .= "\n\t\t<td class=\"results\">Right overbank</td>";
				$html .= "\n\t\t<td class=\"totals\">Total</td>";
			}
			$html .= "\n\t</tr>";
		}
		
		# Second row displaying the total cross section area
		$html .= $this->generateSummaryRow ("columnArea", '', $result, $tally, $channels, "Total cross section area in $unitDescription<sup>2</sup>", $roundTo, $stages, $showColumnX);
		
		# Third row displaying the total wetted perimeter
		$html .= $this->generateSummaryRow ("wettedPerimeter", '', $result, $tally, $channels, "Total wetted perimeter in $unitDescription", $roundTo, $stages, $showColumnX);
		
		# Maximum depth row
		$html .= $this->generateSummaryRow ("depthComponents", $componentsSplit, $result, $tally, $channels, "Maximum depth in $unitDescription", $roundTo, $stages, $showColumnX);
		
		# If an overbank figure has been entered, add additional rows displaying the maximum depth and total width
		if ($data['overbank'] != '') {
			
			# Total width row
			$html .= $this->generateSummaryRow ("widthComponents", '', $result, $tally, $channels, "Total width in $unitDescription", $roundTo, $stages, $showColumnX);
		}
		
		# Fourth row displaying the hydraulic radius
		$html .= "\n\t<tr>";
		$html .= "\n\t\t<td class=\"title\"" . ($showColumnX ? ' colspan="2"' : '') . ">Hydraulic radius in $unitDescription</td>";
		for ($stage = 0; $stage < $stages; $stage++) {
			for ($channel = 0; $channel < $channels; $channel++) {
				# Show the total value in the last sub-column
				if ($channel != ($channels - 1)) {
					$html .= "\n\t\t<td class=\"results\">&nbsp;</td>";
				} else {
					$cssClass = (($data['overbank'] != "") ? 'totals' : 'results');
					$html .= "\n\t\t<td class=\"$cssClass\">" . number_format(round($result[$stage]['hydraulicRadius'], $roundTo), 3) . "</td>";
				}
			}
		}
		$html .= "\n\t</tr>";
		
		# Add in the HTML from each of the individual components, as generated earlier
		$html .= $componentsHtml['columnArea'];
		$html .= $componentsHtml['wettedPerimeter'];
		if ($data['overbank'] != "") {
			$html .= $componentsHtml['depth'];
			$html .= $componentsHtml['width'];
		}
		
		# Close the table
		$html .= "\n</table>";
		
		# Finally, show the introductory text
		echo $introductoryHtml;
		
		# Add the Excel export facility
		echo $exportHtml = $this->exportToExcelButton ($html);
		
		# Show the HTML results
		echo $html;
		
		# Return the result for future carrying through
		return array ($result, $componentKeys);
	}
	
	
	# Function to generate the summary rows
	private function generateSummaryRow ($dataSet, $componentsSplit, $result, $tally, $channels, $description, $roundTo, $stages, $showColumnX)
	{
		# Generate the HTML
		$html = "\n\t<tr>";
		$html .= "\n\t\t<td class=\"title\"" . ($showColumnX ? ' colspan="2"' : '') . ">$description</td>";
		
		for ($stage = 0; $stage < $stages; $stage++) {
			switch ($channels) {
				case 4:
					if ($dataSet == "depthComponents") {
						$html .= "\n\t\t<td class=\"results\">" . (isSet ($componentsSplit['depthComponents'][$stage]['left']) ? number_format(round(max($componentsSplit['depthComponents'][$stage]['left']), $roundTo), 3) : 'n/a') . "</td>";
						$html .= "\n\t\t<td class=\"results\">" . number_format(round(max($componentsSplit['depthComponents'][$stage]['main']), $roundTo), 3) . "</td>";
						$html .= "\n\t\t<td class=\"results\">" . (isSet ($componentsSplit['depthComponents'][$stage]['right']) ? number_format(round(max($componentsSplit['depthComponents'][$stage]['right']), $roundTo), 3) : 'n/a') . "</td>";
						# Then show the highest of all the values within the multi-dimensional array $componentsSplit['depthComponents'][$stage][*]
						#!# Hack is simply to take the ['main'] value, rather than take the maximum of all those values which do exist
						$html .= "\n\t\t<td class=\"totals\">" . number_format(round(max($componentsSplit['depthComponents'][$stage]['main']), $roundTo), 3) . "</td>";
					} else {
						$html .= "\n\t\t<td class=\"results\">" . number_format(round($tally[$dataSet][$stage]['left'], $roundTo), 3) . "</td>";
						$html .= "\n\t\t<td class=\"results\">" . number_format(round($tally[$dataSet][$stage]['main'], $roundTo), 3) . "</td>";
						$html .= "\n\t\t<td class=\"results\">" . number_format(round($tally[$dataSet][$stage]['right'], $roundTo), 3) . "</td>";
						$html .= "\n\t\t<td class=\"totals\">" . number_format(round(array_sum($tally[$dataSet][$stage]), $roundTo), 3) . "</td>";
					}
					break;
				default:
					#!# Hack to adjust the name - not clear why this is necessary
					if ($dataSet == "columnArea") {$dataSet = "totalArea";}
					if ($dataSet == "wettedPerimeter") {$dataSet = "totalWettedPerimeter";}
					if ($dataSet == "depthComponents") {$dataSet = "maximumDepth";}
					$html .= "\n\t\t<td class=\"results\">" . number_format(round($result[$stage][$dataSet], $roundTo), 3) . "</td>";
					break;
			}
		}
		$html .= "\n\t</tr>";
		
		# Return the HTML
		return $html;
	}
	
	
	# Function to pregenerate the individual component rows
	private function pregenerateIndividualComponents ($components, $channels, $data, $componentKeys, $interface, $result, $roundTo, $stages, $showColumnX, $unitDescription)
	{
		# Fifth row containing the individual area components
		list ($componentsHtml['columnArea'], $tally['columnArea'], $componentsSplit['columnArea']) = $this->generateIndividualComponentsGroup (
			'columnArea',
			"Individual area components in $unitDescription<sup>2</sup>",
			$components, $channels, $data, $componentKeys, $interface, $result, $roundTo, $stages, $showColumnX
		);
		
		# Sixth row containing the individual wetted perimeter components
		list ($componentsHtml['wettedPerimeter'], $tally['wettedPerimeter'], $componentsSplit['wettedPerimeter']) = $this->generateIndividualComponentsGroup (
			'wettedPerimeter',
			"Individual wetted perimeter components in $unitDescription",
			$components, $channels, $data, $componentKeys, $interface, $result, $roundTo, $stages, $showColumnX
		);
		
		# Individual depth components
		list ($componentsHtml['depth'], $tally['depthComponents'], $componentsSplit['depthComponents']) = $this->generateIndividualComponentsGroup (
			'depth',
			"Individual depth components in $unitDescription",
			$components, $channels, $data, $componentKeys, $interface, $result, $roundTo, $stages, $showColumnX
		);
		
		# If an overbank figure has been entered, add additional rows displaying the individual depth components and individual width components
		if ($data['overbank'] != "") {
			
			# Individual width components
			list ($componentsHtml['width'], $tally['widthComponents'], $componentsSplit['widthComponents']) = $this->generateIndividualComponentsGroup (
				'width',
				"Individual width components in $unitDescription",
				$components, $channels, $data, $componentKeys, $interface, $result, $roundTo, $stages, $showColumnX
			);
		}
		
		# Return the arrays containing the HTML and the tally
		return array ($componentsHtml, $tally, $componentsSplit);
	}
	
	
	# Function to display to generate the display of channel data
	private function generateIndividualComponentsGroup ($dataSet, $heading, $components, $channels, $data, $componentKeys, $interface, $result, $roundTo, $stages, $showColumnX)
	{
		# Define the HTML for an empty cell, as a shortcut
		$emptyCellHtml = "\n\t\t<td class=\"results\">&nbsp;</td>";
		
		# Start the row
		$html = "\n\t<tr>";
		$html .= "\n\t\t<td class=\"title\" rowspan=\"{$components}\"><$heading></td>";
		
		# Loop through each row; start a new <tr> tag unless it's the first row
		$startNewTableRowFlag = false;
		foreach ($componentKeys as $componentKey) {
			if ($startNewTableRowFlag) {$html .= "\n\t<tr>";}
			$startNewTableRowFlag = true;
			
			# Start the row
			#!# Remove rounding if the actual value is an exact whole number
			$html .= ($showColumnX ? "\n\t\t<td class=\"comment\">" . number_format(round($data['x'][$componentKey], $roundTo), 3) . "&nbsp;to&nbsp;"  . number_format(round($data['x'][($componentKey + 1)], $roundTo), 3) . "</td>" : '');	
			
			# Loop through each stage
			for ($stage = 0; $stage < $stages; $stage++) {
				
				# Start a tally to add up the values
				if (!isSet ($tally[$dataSet][$stage])) {
					$tally[$dataSet][$stage]['left'] = 0;
					$tally[$dataSet][$stage]['right'] = 0;
					$tally[$dataSet][$stage]['main'] = 0;
				}
				
				# Get the actual result for this stage
				#!# Need to get rid of .000 where it's not actually being rounded but is exact
				$resultCellHtml = "\n\t\t<td class=\"results\">" . (isSet ($result[$stage][$dataSet][$componentKey]) ? number_format(round($result[$stage][$dataSet][$componentKey], $roundTo), 3) : "&nbsp;") . "</td>";
				
				# If, instead, there are three channels, generate the left, right and middle cells and the final empty cell
				if ($channels > 1) {
					
					# Create a tally for the left, right and main channels
					if ($data['x'][$componentKey] < $interface['left']) {
						$resultCellHtml = $resultCellHtml . $emptyCellHtml. $emptyCellHtml . $emptyCellHtml;
						if (isSet ($result[$stage][$dataSet][$componentKey])) {
							$tally[$dataSet][$stage]['left'] = $tally[$dataSet][$stage]['left'] + $result[$stage][$dataSet][$componentKey];
							$componentsSplit[$dataSet][$stage]['left'][$componentKey] = $result[$stage][$dataSet][$componentKey];
						}
					} else if ($data['x'][$componentKey] > $interface['right']) {
						$resultCellHtml = $emptyCellHtml. $emptyCellHtml . $resultCellHtml . $emptyCellHtml;
						if (isSet ($result[$stage][$dataSet][$componentKey])) {
							$tally[$dataSet][$stage]['right'] = $tally[$dataSet][$stage]['right'] + $result[$stage][$dataSet][$componentKey];
							$componentsSplit[$dataSet][$stage]['right'][$componentKey] = $result[$stage][$dataSet][$componentKey];
						}
					} else {
						$resultCellHtml = $emptyCellHtml . $resultCellHtml . $emptyCellHtml . $emptyCellHtml;
						if (isSet ($result[$stage][$dataSet][$componentKey])) {
							$tally[$dataSet][$stage]['main'] = $tally[$dataSet][$stage]['main'] + $result[$stage][$dataSet][$componentKey];
							$componentsSplit[$dataSet][$stage]['main'][$componentKey] = $result[$stage][$dataSet][$componentKey];
						}
					}
				}
				
				# Add the result onto the start of the row
				$html .= $resultCellHtml;
			}
			
			# Finish the row
			$html .= "\n\t</tr>";
		}
		
		# Return the result
		return array ($html, $tally[$dataSet], (isSet ($componentsSplit[$dataSet]) ? $componentsSplit[$dataSet] : ''));
	}
	
	
	# Function to display the discharge calculation form (used in the area calculation program
	private function defineDischargeCalculationForm ($data, $equations, $roundTo)
	{
		# If the form hasn't been submitted the first time (i.e. is bridging, add in the bridge button text
		if (!isSet ($_POST['discharge'])) {
			
			# Override the submit button text
			#!# Ideally the link down to the box below wouldn't be necessary
			echo '<a name="discharge"></a>';
			
			# Create the form
			$form = new form (array (
				'name' => 'discharge',
				'submitButtonText' => 'Use these results for calculating the discharge',
			));
			
		# Once the form has been submitted the first time, add in the extra items
		} else {
			
			# Create the form
			$form = new form (array (
				'name' => 'discharge',
				'displayRestrictions' => false,
			));
			
			$form->heading (2, 'Optional discharge calculation');
			
			$form->input (array (
				'name'			=> 'slope',
				'title'					=> 'Slope',
				'description'	=> 'Enter the bed slope for your reach.',
				'required'				=> true,
				'enforceNumeric'		=> true,
				'size'					=> 20,
				'maxlength'				=> 128,
			));
			
			$form->checkboxes (array (
				'name'			=> 'equations',
				'values'			=> $equations,
				'title'					=> 'Equations',
				'description'	=> 'Please select equation(s) to be run.<br /><br />See also: <a href="equations.pdf" target="_blank"> details for each of these equations</a><br />[PDF opens in a new window].',
				'required'		=> 1,
			));
			
			$form->heading ('p', "<h4>Manning's n specification (applies only if 'Manning' selected in above list)</h4>\n<p>Enter <strong>either</strong> a single value for Manning's n in the first box...</p>");
			
			# The 'single' version of the Manning's N field
			$form->input (array (
				'name'			=> 'manningsn',
				'title'					=> "<strong>Single value</strong> for Manning's n",
				'description'	=> 'Numeric values only.',
				'required'				=> false,
				'enforceNumeric'		=> true,
				'size'					=> 10,
				'maxlength'				=> 128,
			));
			
			$form->heading ('p', '... <strong>or</strong> individual values in the following boxes:');
			
			# Temporarily serialise the result to obtain values
			$result = unserialize (urldecode ($data['hidden']['result']));
			$keys = unserialize (urldecode ($data['hidden']['keys']));
			
			# Create a new set of form fields (which, as usual, must be uniquely named) for the 'Manning's N' input
			$totalKeys = count ($keys) - 1;
			for ($i = 0; $i < $totalKeys; $i++) {
				
				# Actually create the form field
				$form->input (array (
					'name'			=> 'manningsn' . $i,
					'title'					=> "Manning's n at " . number_format (round ($keys[$i], $roundTo), 3) . '&nbsp;to&nbsp;' . number_format (round ($keys[$i + 1], $roundTo), 3),
					'description'	=> 'Numeric values only.',
					'required'				=> false,
					'enforceNumeric'		=> true,
					'size'					=> 10,
					'maxlength'				=> 128,
				));
			}
		}
		
		# Carry over the data
		$form->hidden (array (
			'values'			=> $data['hidden'],
			'name'			=> 'hidden',
		));
		
		# Return the form
		return $form;
	}
	
	
	# Function to add a button to allow exporting to Excel
	private function exportToExcelButton ($html)
	{
		# Build up a text string containing the HTML
		#!# This is not ideal - would be better to have CSV output
		$exportHtml = "\n" . '<form method="post" action="export.html">';
		$exportHtml .= "\n\t" . '<input type="hidden" name="html" value="' . htmlspecialchars ($html) . '" />';
		$exportHtml .= "\n\t" . '<input value="Export this data to Excel &nbsp; &nbsp;[Alt+e]" accesskey="e" type="submit" class="button" />';
		$exportHtml .= "\n</form>\n";
		
		# Show the HTML
		return $exportHtml;
	}
	
	
	
	# Function to export the data to other formats
	private function export ($html)
	{
		# Check there has been posted HTML
		if ($html == '') {
			$this->throwError (8);
			return false;
		}
		
		# Convert the posted HTML out from entities
		//$html = strtr ($html, array_flip (get_html_translation_table (HTML_ENTITIES)));
		//$html = preg_replace ("/&#([0-9]+);/me", "chr('\\1')", $html);
		
		# Add borders to the table
		$html = str_replace ('<table>', '<table border="1">', $html);
		
		# Send Excel headers
		header("Content-Type: application/vnd.ms-excel");
		header("Content-disposition: attachment; filename=riverslice.xls");
		header("Expires: 0");
		header("Cache-Control: must-revalidate, post-check=0, pre-check=0");
		
		# Show the HTML
		echo $html;
	}
	
	
	# Function to run the discharge (second) stage calculations and presentation
	private function dischargeStage ($roundTo)
	{
		# Define the equations available
		$equations = array (
			'Manning (1891)',
			'Dingman & Sharma (1997)',
			'Golubtsov (1969)',
			'Riggs (1976)',
			'Williams (1978)',
		);
		
		# Define a bridge form, containing the data carried over
		$form = $this->defineDischargeCalculationForm ($_POST['discharge'], $equations, $roundTo);
		
		# Try to process the form and, if complete, show the result
		if ($data = $form->process ()) {
			
			# Unserialised the serialised array data shunted through the form and destroy the original
			$result = unserialize (urldecode ($data['hidden']['result']));
			$keys = unserialize (urldecode ($data['hidden']['keys']));
			unset ($data['hidden']);
			
			# Check the status of the row of the 'individual' Manning's n fields
			$totalFieldsToCheck = count ($keys) - 1;
			$fieldsEntered = 0;
			for ($component = 0; $component < $totalFieldsToCheck; $component++) {
				if ($data['manningsn' . $component] != "") {
					$fieldsEntered++;
				}
			}
			
			# Check whether the user has entered both the single overall field and the individual field
			if (($data['manningsn'] != "") && ($fieldsEntered == $totalFieldsToCheck)) {
				$this->throwError (9);
			} else {
				
				# Do an overall check that EITHER the single overall field is completed or all of the individual fields are completed
				#!# Account in the interface for cases where the set of individual boxes is not fully complete
				if (($data['manningsn'] != "") || ($fieldsEntered == $totalFieldsToCheck)) {
					
					# Count the number of stages
					$stages = count ($result);
					
					# Assign introductory HTML
					$headingHtml = "\n<h2>Discharge</h2>";
					$introductoryHtml = "\n<p>The following table provides discharge results in " . (($result[0]['units'] == "Metric (metres)") ? 'm' : 'f') . '<sup>3</sup>s<sup>-1</sup>.</p>';
					
					# Start a variable to hold the main HTML
					$html = "\n\n<table>";
					
					# Start off a second set of HTML for the multi-component table, in case it is needed
					$multicomponentHtml = "\n<h3>Conveyance components</h3>\n\n<table>";
					$showMulticomponentHtml = false;
					
					# Insert the heading row for the main table
					$html .= "\n\t<tr>";
					$html .= "\n\t\t<th>Equation</th>";
					for ($stage = 0; $stage < $stages; $stage++) {
						$html .= "\n\t\t<th>Stage " . $result[$stage]['stage'] . '</th>';
					}
					$html .= "\n\t</tr>";
					
					# Insert the heading row for the multicomponent table
					$multicomponentHtml .= "\n\t<tr>";
					$multicomponentHtml .= "\n\t\t<th>x co-ordinates</th>";
					$multicomponentHtml .= "\n\t\t<th>n</th>";
					for ($stage = 0; $stage < $stages; $stage++) {
						$multicomponentHtml .= "\n\t\t<th>Stage " . $result[$stage]['stage'] . '</th>';
					}
					$multicomponentHtml .= "\n\t</tr>";
					
					# Loop through each equation and run the calculations for it if requested
					foreach ($equations as $equation) {
						if ($data['equations'][$equation]) {
							
							# Start a row for each equation
							$html .= "\n\t<tr>";
							$html .= "\n\t\t<td>" . htmlspecialchars ($equation) . '</td>';
							
							# Run the calculation for each stage
							for ($stage = 0; $stage < $stages; $stage++) {
								
								# Convert the submitted or carried values to the equation arguments, making sure they are explicitly floats [requires PHP4 >= 4.2.0]
								$a = floatval($result[$stage]['totalArea']);
								$d = floatval($result[$stage]['maximumDepth']);
								$r = floatval($result[$stage]['hydraulicRadius']);
								$n = floatval($data['manningsn']);
								$s = floatval($data['slope']);
								
								# Do the appropriate equation
								switch ($equation) {
									case 'Manning (1891)':
										# Switch between the equations for the single value for Manning's n and the individual rows
										if ($data['manningsn'] != '') {
											$q = ($a * pow ($r, 2/3) * pow ($s, 0.5)) / $n;
										} else {
											
											# Establish that the multicomponentHtml table should be shown
											$showMulticomponentHtml = true;
											
											# Start a variable to hold the amount for the equation
											$q = 0;
											
											# Loop through each component
											$temporaryKeys = array_keys($result[$stage]['columnArea']);
											$i = 0;
											foreach ($temporaryKeys as $temporaryKey) {
												
												# Obtain the area and wetted perimeter for each component
												$a = $result[$stage]['columnArea'][$temporaryKey];
												$wp = $result[$stage]['wettedPerimeter'][$temporaryKey];
												
												# Do the equation for this component and add that to the amount calculated so far
												$conveyance = (($a / floatval($data['manningsn' . $i])) * pow (($a / $wp), 2/3));
												
												# Add this particular conveyance result to the total being calculated
												$q = $q + $conveyance;
												
												# Add the calculated values to an array of table cells which will be generated in a moment
												$conveyanceComponents[$temporaryKey]['xCoordinateRange'] = $keys[$i] . '&nbsp;to&nbsp;' . $keys[$i + 1];
												$conveyanceComponents[$temporaryKey][$stage] = number_format (round ($conveyance, $roundTo), 3);
												$conveyanceComponents[$temporaryKey]['userData'] = $i;
												
												# Increment the loop used to get $data['manningsn0'], $data['manningsn1'], ...
												$i++;
											}
											
											# Finalise the equation
											$q = $q * pow ($s, 0.5);
										}
										break;
									case 'Dingman & Sharma (1997)':
										# (log($s)/log(10) is a workaround for the lack of log($s, 10) in PHP v. < 4.3
										$q = 1.56 * pow ($a, 1.173) * pow ($r, 0.4) * pow ($s, (-0.0543 * (log($s)/log(10))));
										break;
									case 'Golubtsov (1969)':
										$q = 4.5 * $a * pow ($d, 2/3) * pow (($s + 0.001), 0.17);
										break;
									case 'Riggs (1976)':
										$q = 1.55 * pow ($a, 4/3) * pow ($s, (0.05 - (0.056 * (log($s)/log(10)))));
										break;
									case 'Williams (1978)':
										$q = 4 * pow ($a, 1.21) * pow ($s, 0.28);
										break;
								}
								
								# Add the result
								$html .= "\n\t\t<td class=\"results\">" . number_format(round($q, $roundTo), 3) . '</td>';
							}
							
							# End the equation row
							$html .= "\n\t</tr>";
						}
					}
					
					# End the table
					$html .= "\n</table>\n\n";
					
					# If the multicomponent table has been created (i.e. if using the multicomponent Manning's n part of the second form), construct then display it
					if ($showMulticomponentHtml) {
						
						# Convert the conveyance components array to the HTML table layout
						foreach ($temporaryKeys as $temporaryKey) {
							$multicomponentHtml .= "\n\t<tr>";
							$multicomponentHtml .= "\n\t\t<td>" . $conveyanceComponents[$temporaryKey]['xCoordinateRange'] . "</td>";
							$temporaryName = $conveyanceComponents[$temporaryKey]['userData'];
							$multicomponentHtml .= "\n\t\t<td>" . $data['manningsn' . $temporaryName] . "</td>";
							for ($stage = 0; $stage < $stages; $stage++) {
								$multicomponentHtml .= "\n\t\t<td class=\"results\">" . ((isSet ($conveyanceComponents[$temporaryKey][$stage])) ? $conveyanceComponents[$temporaryKey][$stage] : '&nbsp;') . "</td>";
							}
							$multicomponentHtml .= "\n\t</tr>";
						}
						$multicomponentHtml .= "\n</table>\n\n";
						
						# Add the multicomponent HTML to the main HTML
						$html .= $multicomponentHtml;
					}
					
					# Assign HTML for the link to the equations PDF
					$equationLinkHtml = "\n" . '<p><a href="equations.pdf" target="_blank">Details for each of these equations</a> [PDF opens in a new window].</p>';
					
					# Add the Excel export facility and button at this point
					$exportHtml = $this->exportToExcelButton ($headingHtml . $introductoryHtml . $html);
					
					# Display all the HTML
					echo $headingHtml . $exportHtml . $introductoryHtml . $equationLinkHtml . $html;
					
				} else {
					$this->throwError (10);
				}
			}
		}
	}
	
	
	# Function to display error messages
	#!# Rework this to put the errors messages directly in the calling code
	public function throwError ($errorNumber)
	{
		# Specify particular reusable phrases
		$goBack = " Please go back to correct your data and try again.";
		
		# Select the error number
		switch ($errorNumber) {
			case 1:
				$errorMessage = "Your designated STAGE is <strong>below or equal to</strong> the minimum elevation of your cross section." . $goBack;
				break;
			case 2:
				$errorMessage = "You selected <em>Stage calculation from area</em> but entered more than one area." . $goBack;
				break;
			case 3:
				$errorMessage = "The area must be numeric." . $goBack;
				break;
			case 4:
				$errorMessage = "You have entered geometry for a compound cross-section; this facility can only analyse single channels." . $goBack;
				break;
			case 5:
				$errorMessage = "Warning: one or more stage(s) do not intersect your channel geometry. The area calculations will be incomplete as a result.";
				break;
			case 6:
				$errorMessage = "Your overbank analysis elevation doesn't intersect your channel geometry." . $goBack;
				break;
			case 7:
				$errorMessage = "Warning: you entered more than one area. The first will be used in the calculation.";
				break;
			case 8:
				$errorMessage = 'No data was posted - please use this export facility via the <a href="' . preg_replace ('|/index.html$|', '/', $_SERVER['PHP_SELF']) . '">form interface</a>.';
				break;
			case 9:
				$errorMessage = "You entered both a single overall value and a complete set of individual values for Manning's n - please do either one or the other!" . $goBack;
				break;
			case 10:
				$errorMessage = "You didn't enter either a single overall value or a complete set of individual values for Manning's n." . $goBack;
				break;
			case 11:
				$errorMessage = "You entered a negative value for area." . $goBack;
				break;
			default:
				$errorMessage = "A strange yet unknown error has occured (error number: $errorNumber)";
				$mailAdministrator = true;
				break;
		}
		
		# Show the error message
		echo "\n" . '<p class="warning">Error: ' . $errorMessage . '</p>';
		
		# If a programming error occurred, e-mail the administrator
		if (isSet ($mailAdministrator)) {
			$subject = "Error in hydro program";
			$message = "Error {$errorNumber} occurred in the hydro program, which is a programming rather than user error. This is \"$errorMessage\".";
			$mailheaders = 'From: Hydro program <' . $this->settings['administratorEmail'] . ">\n";
			application::utf8Mail ($this->settings['administratorEmail'], $subject, $message, $mailheaders);
		}
		
		# Return the result
		return true;
	}
	
	
	# Function to display introductory information about the facility
	public function about ()
	{
		# Create the HTML
		$html = '
			<p>This facility allows you interactively to calculate the basic hydraulic geometry properties of a river cross section.</p>
			<ul class="spaced">
				<li>Simply enter your surveyed channel cross section data, and specify the stage(s) at which you wish the calculations to be made. </li>
				<li>The output includes the depth, area, wetted perimeter and hydraulic radius of your cross section. </li>
				<li>You can also specify an <strong>overbank stage</strong>, and the program will partition the results into <strong>left overbank</strong>, <strong>main channel</strong>, and <strong>right overbank</strong> sections.</li>
				<li>Finally, you may use the results to calculate a <strong>discharge estimate</strong>, using a range of common discharge equations.</li>
				<li>You may also perform the reverse: this website can calculate stage from a cross section area that you specify, given your channel cross section data.</li>
				<li>Please address any questions on the equations, calculations and theoretical aspects to Ren&eacute;e Kidson; please address any script/web questions to the Webmaster.</li>
			</ul>
			<br />
			<p>The <a href="https://github.com/camunigeog/riverslice/" target="_blank">code of this facility</a> is available freely under the GPL open source licence.</p>
		';
		
		# Show the HTML
		echo $html;
	}
}

?>
