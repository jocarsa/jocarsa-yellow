<?php
/**
 * Recursively scan a directory to get:
 *  - size: total size of files (in bytes)
 *  - fileCount: total number of files in this subtree
 *  - children: array of child nodes (if folder)
 *
 * @param string $dir Directory (or file) path
 * @return array
 */

// Define an array of folders to exclude from scanning
$excludedFolders = array('.git');

function scanDirectory($dir) {
    global $excludedFolders; // Access the excluded folders array
    // If it's a directory
    if (is_dir($dir)) {
        $node = [
            'name' => basename($dir),
            'size' => 0,
            'fileCount' => 0,
            'children' => []
        ];
        $items = scandir($dir);
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') continue;
            $path = $dir . DIRECTORY_SEPARATOR . $item;
            // Skip this directory if it is in the excluded folders list
            if (is_dir($path) && in_array($item, $excludedFolders)) {
                continue;
            }
            if (is_dir($path)) {
                // Recurse into subdirectory
                $child = scanDirectory($path);
                $node['size'] += $child['size'];
                $node['fileCount'] += $child['fileCount'];
                $node['children'][] = $child;
            } else {
                // It's a file
                $fileSize = filesize($path);
                $node['size'] += $fileSize;
                $node['fileCount'] += 1;
                $node['children'][] = [
                    'name' => $item,
                    'size' => $fileSize,
                    'fileCount' => 1,
                    'children' => []
                ];
            }
        }
    } else {
        // If $dir is actually a file (edge case)
        $node = [
            'name' => basename($dir),
            'size' => filesize($dir),
            'fileCount' => 1,
            'children' => []
        ];
    }
    return $node;
}

// 1. Scan from the current folder:
$data = scanDirectory('../CRM');

?>
<!DOCTYPE html>
<html>
<head>
  <meta charset="utf-8">
  <title>jocarsa | yellow</title>
  <style>
  @import url('https://static.jocarsa.com/fuentes/ubuntu-font-family-0.83/ubuntu.css');

    body {
      margin: 0;
      padding: 20px;
      font-family: Ubuntu,"Segoe UI", Tahoma, Geneva, Verdana, sans-serif;
      background: linear-gradient(135deg, #1e3c72, #2a5298);
      color: #ffffff;
      text-align: center;
    }
    h1 {
      margin-bottom: 20px;
      text-shadow: 2px 2px 4px rgba(0,0,0,0.5);
      display: flex;
	flex-direction: row;
	flex-wrap: nowrap;
	justify-content: center;
	align-items: center;
	align-content: stretch;
    }
    h1 img{
    	width:80px;
    	margin-right:10px;
    }
    #chart {
      display: inline-block;
      position: relative;
    }
    .tooltip {
      position: absolute;
      background: rgba(0,0,0,0.7);
      color: #fff;
      padding: 6px 10px;
      border-radius: 4px;
      font-size: 12px;
      pointer-events: none;
      opacity: 0;
      transition: opacity 0.3s;
    }
    .arc path {
      stroke: #fff;
      cursor: pointer;
      transition: transform 0.2s, fill-opacity 0.2s;
    }
    .arc path:hover {
      transform: scale(1.05);
      fill-opacity: 0.8;
    }
    .arc text {
      font-size: 10px;
      fill: #fff;
      text-anchor: middle;
      pointer-events: none;
      dominant-baseline: middle;
      text-shadow: 1px 1px 2px rgba(0,0,0,0.6);
    }
    button {
      margin-bottom: 10px;
      padding: 6px 12px;
      background: #ff9800;
      color: #fff;
      border: none;
      border-radius: 4px;
      cursor: pointer;
      font-weight: bold;
    }
    button:hover {
      background: #e68900;
    }
  </style>
</head>
<body>
  <h1><img src="yellow.png">jocarsa | yellow</h1>
  <div id="controls"></div>
  <div id="chart"></div>

  <script>
    // Directory data from PHP
    const data = <?php echo json_encode($data); ?>;

    // 1) Add parent references so we can "go back" up the tree
    function addParentRefs(node, parent = null) {
      node.parent = parent;
      if (node.children) {
        for (let child of node.children) {
          addParentRefs(child, node);
        }
      }
    }
    addParentRefs(data);

    // We'll keep a global "currentRoot" so we can re-draw from different nodes
    let currentRoot = data;

    // Helper to find max depth for ring thickness
    function findMaxDepth(node, depth = 0) {
      if (!node.children || node.children.length === 0) {
        return depth;
      }
      let max = depth;
      for (let child of node.children) {
        max = Math.max(max, findMaxDepth(child, depth + 1));
      }
      return max;
    }

    // A mini function to gather all descendants
    function getAllNodes(root) {
      const nodes = [];
      function traverse(n) {
        nodes.push(n);
        if (n.children) {
          for (let c of n.children) {
            traverse(c);
          }
        }
      }
      traverse(root);
      return nodes;
    }

    // Our manual "sunburst layout" function
    function computeSunburst(node, startAngle, endAngle, depth, ringThickness) {
      node.x0 = startAngle;
      node.x1 = endAngle;
      node.y0 = depth * ringThickness;
      node.y1 = (depth + 1) * ringThickness;

      if (!node.children || node.children.length === 0) {
        return;
      }
      let currentAngle = startAngle;
      for (let child of node.children) {
        const fraction = (child.size && node.size) ? child.size / node.size : 0;
        const nextAngle = currentAngle + (endAngle - startAngle) * fraction;
        computeSunburst(child, currentAngle, nextAngle, depth + 1, ringThickness);
        currentAngle = nextAngle;
      }
    }

    // Arc path generator (like a tiny version of d3.arc)
    function arcPath(x0, x1, r0, r1) {
      // Convert polar coords to Cartesian
      function polarToCartesian(r, angle) {
        return [r * Math.cos(angle), r * Math.sin(angle)];
      }

      const largeArc = (x1 - x0) > Math.PI ? 1 : 0;

      const [xStartOuter, yStartOuter] = polarToCartesian(r1, x0);
      const [xEndOuter,   yEndOuter]   = polarToCartesian(r1, x1);
      const [xStartInner, yStartInner] = polarToCartesian(r0, x1);
      const [xEndInner,   yEndInner]   = polarToCartesian(r0, x0);

      const d = [
        `M ${xStartOuter},${yStartOuter}`,
        `A ${r1},${r1} 0 ${largeArc} 1 ${xEndOuter},${yEndOuter}`,
        `L ${xStartInner},${yStartInner}`,
        `A ${r0},${r0} 0 ${largeArc} 0 ${xEndInner},${yEndInner}`,
        "Z"
      ].join(" ");
      return d;
    }

    // Simple color function by angle
    function colorByAngle(angleFraction) {
      const hue = 360 * angleFraction;
      return `hsl(${hue}, 70%, 50%)`;
    }

    // We'll store references to the SVG elements in a drawChart function
    function drawChart(root) {
      currentRoot = root;

      // Clear old chart & controls
      const chartDiv = document.getElementById("chart");
      chartDiv.innerHTML = "";
      const controlsDiv = document.getElementById("controls");
      controlsDiv.innerHTML = "";

      // If we can go back, show a "Go Back" button
      if (root.parent) {
        const backBtn = document.createElement("button");
        backBtn.textContent = "Go Back";
        backBtn.addEventListener("click", () => {
          drawChart(root.parent);
        });
        controlsDiv.appendChild(backBtn);
      }

      // Create the tooltip
      let tooltip = document.querySelector(".tooltip");
      if (!tooltip) {
        tooltip = document.createElement("div");
        tooltip.className = "tooltip";
        document.body.appendChild(tooltip);
      }

      // Find maximum depth from this root
      const maxDepth = findMaxDepth(root);
      const outerRadius = 300;
      const ringThickness = outerRadius / (maxDepth + 1);

      // Compute angles for each node
      computeSunburst(root, 0, 2 * Math.PI, 0, ringThickness);

      // Gather all nodes from this root
      const nodes = getAllNodes(root);

      // Build the SVG
      const width = 2 * outerRadius + 20;
      const height = 2 * outerRadius + 20;
      const svgNS = "http://www.w3.org/2000/svg";

      const svg = document.createElementNS(svgNS, "svg");
      svg.setAttribute("width", width);
      svg.setAttribute("height", height);

      const g = document.createElementNS(svgNS, "g");
      g.setAttribute("transform", `translate(${outerRadius + 10},${outerRadius + 10})`);
      svg.appendChild(g);
      chartDiv.appendChild(svg);

      // Draw arcs
      nodes.forEach(node => {
        const x0 = node.x0, x1 = node.x1, r0 = node.y0, r1 = node.y1;
        // If node.size=0 or fraction=0, it won't draw anything visible, but let's proceed
        const pathEl = document.createElementNS(svgNS, "path");
        pathEl.setAttribute("d", arcPath(x0, x1, r0, r1));
        // Color by midpoint angle
        const midAngle = 0.5 * (x0 + x1);
        const angleFraction = midAngle / (2 * Math.PI);
        pathEl.setAttribute("fill", colorByAngle(angleFraction));

        // Show tooltip
        pathEl.addEventListener("mouseover", evt => {
          tooltip.style.opacity = "1";
          const sizeMB = (node.size / (1024*1024)).toFixed(2);
          if (node.children && node.children.length > 0) {
            // It's a folder
            const fileWord = node.fileCount === 1 ? "file" : "files";
            tooltip.innerHTML = `<strong>${node.name}</strong><br/>${node.fileCount} ${fileWord}, ${sizeMB} MB`;
          } else {
            // It's a file
            tooltip.innerHTML = `<strong>${node.name}</strong><br/>${sizeMB} MB`;
          }
        });
        pathEl.addEventListener("mousemove", evt => {
          tooltip.style.left = (evt.pageX + 10) + "px";
          tooltip.style.top = (evt.pageY - 20) + "px";
        });
        pathEl.addEventListener("mouseout", evt => {
          tooltip.style.opacity = "0";
        });

        // Click to zoom if it's a folder
        pathEl.addEventListener("click", evt => {
          // Only if node has children
          if (node.children && node.children.length > 0) {
            drawChart(node);
          }
        });

        const arcGroup = document.createElementNS(svgNS, "g");
        arcGroup.setAttribute("class", "arc");
        arcGroup.appendChild(pathEl);

        // Add text label if arc is large enough
        const angleSpan = x1 - x0;
        if (angleSpan > 0.10) { // ~6 degrees
          const textEl = document.createElementNS(svgNS, "text");
          const mid = x0 + angleSpan / 2;
          const rMid = (r0 + r1) / 2;
          const labelX = rMid * Math.cos(mid);
          const labelY = rMid * Math.sin(mid);
          textEl.setAttribute("x", labelX);
          textEl.setAttribute("y", labelY);
          const sizeMB = (node.size / (1024*1024)).toFixed(2);
          textEl.textContent = `${node.name} (${sizeMB} MB)`;
          arcGroup.appendChild(textEl);
        }

        g.appendChild(arcGroup);
      });
    }

    // Draw the initial chart at top-level
    drawChart(currentRoot);
  </script>
</body>
</html>

