<?php
// Define the path for the SQLite database file
// This path will be mounted as a Docker volume for persistence
$dbFile = '/var/www/html/data/cat_weights.sqlite';

// Ensure the data directory exists and is writable
// In a Docker container, this directory should be a volume
if (!is_dir(dirname($dbFile))) {
    // This mkdir block is primarily for local development outside Docker volumes.
    // In Docker with a mounted volume, the directory should already exist and have permissions
    // due to the Dockerfile and docker-compose.yml setup.
    mkdir(dirname($dbFile), 0777, true);
}

// Connect to SQLite database
try {
    $pdo = new PDO("sqlite:" . $dbFile);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // Create weights table if it doesn't exist
    // IMPORTANT: The UNIQUE constraint is now on (cat_name, measure_date)
    // This allows different cats to have entries on the same date,
    // but each cat can only have one entry per date.
    $pdo->exec("CREATE TABLE IF NOT EXISTS weights (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        cat_name TEXT NOT NULL,
        weight REAL NOT NULL,
        measure_date TEXT NOT NULL,
        UNIQUE(cat_name, measure_date)
    )");
} catch (PDOException $e) {
    // In a real application, you'd log this error and show a user-friendly message
    die("Database connection failed: " . $e->getMessage());
}

$message = '';
$messageType = ''; // 'success' or 'error'

// Handle form submission for adding/updating weight
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add_weight') {
    $catName = trim($_POST['cat_name'] ?? '');
    $weight = filter_var($_POST['weight'] ?? '', FILTER_VALIDATE_FLOAT);
    $measureDate = trim($_POST['measure_date'] ?? '');

    // Validate inputs
    if (empty($catName) || $weight === false || $weight <= 0 || empty($measureDate)) {
        $message = 'Please fill in all fields correctly. Weight must be a positive number.';
        $messageType = 'error';
    } else {
        try {
            // Check if a record for this date and cat already exists
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM weights WHERE measure_date = :measure_date AND cat_name = :cat_name");
            $stmt->execute([':measure_date' => $measureDate, ':cat_name' => $catName]);
            if ($stmt->fetchColumn() > 0) {
                // If a record exists, update it
                $stmt = $pdo->prepare("UPDATE weights SET weight = :weight WHERE measure_date = :measure_date AND cat_name = :cat_name");
                $stmt->execute([
                    ':weight' => $weight,
                    ':measure_date' => $measureDate,
                    ':cat_name' => $catName
                ]);
                $message = 'Weight updated successfully for ' . htmlspecialchars($catName) . ' on ' . htmlspecialchars($measureDate) . '.';
                $messageType = 'success';
            } else {
                // Otherwise, insert a new record
                $stmt = $pdo->prepare("INSERT INTO weights (cat_name, weight, measure_date) VALUES (:cat_name, :weight, :measure_date)");
                $stmt->execute([
                    ':cat_name' => $catName,
                    ':weight' => $weight,
                    ':measure_date' => $measureDate
                ]);
                $message = 'Weight added successfully for ' . htmlspecialchars($catName) . '.';
                $messageType = 'success';
            }
        } catch (PDOException $e) {
            // Handle potential unique constraint violation if not caught by the update check
            // This specific error code (SQLSTATE 23000) for SQLite indicates a unique constraint violation
            if ($e->getCode() == '23000') {
                $message = 'A weight entry for ' . htmlspecialchars($catName) . ' on ' . htmlspecialchars($measureDate) . ' already exists. Please update it instead.';
            } else {
                $message = 'Error adding/updating weight: ' . $e->getMessage();
            }
            $messageType = 'error';
        }
    }
}

// Handle deletion of an entry
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete_entry') {
    $entryId = filter_var($_POST['entry_id'] ?? '', FILTER_VALIDATE_INT);

    if ($entryId === false || $entryId <= 0) {
        $message = 'Invalid entry ID for deletion.';
        $messageType = 'error';
    } else {
        try {
            $stmt = $pdo->prepare("DELETE FROM weights WHERE id = :id");
            $stmt->execute([':id' => $entryId]);
            if ($stmt->rowCount() > 0) {
                $message = 'Entry deleted successfully.';
                $messageType = 'success';
            } else {
                $message = 'Entry not found or could not be deleted.';
                $messageType = 'error';
            }
        } catch (PDOException $e) {
            $message = 'Error deleting entry: ' . $e->getMessage();
            $messageType = 'error';
        }
    }
}


// Handle date filter submission
$startDate = $_GET['start_date'] ?? '';
$endDate = $_GET['end_date'] ?? '';
$filterCatName = $_GET['filter_cat_name'] ?? '';

$whereClauses = [];
$params = [];

if (!empty($startDate)) {
    $whereClauses[] = "measure_date >= :start_date";
    $params[':start_date'] = $startDate;
}
if (!empty($endDate)) {
    $whereClauses[] = "measure_date <= :end_date";
    $params[':end_date'] = $endDate;
}
if (!empty($filterCatName)) {
    $whereClauses[] = "cat_name = :filter_cat_name";
    $params[':filter_cat_name'] = $filterCatName;
}

$sql = "SELECT * FROM weights";
if (!empty($whereClauses)) {
    $sql .= " WHERE " . implode(" AND ", $whereClauses);
}
$sql .= " ORDER BY measure_date ASC";

// Fetch all weight data for display and charting, ordered by date
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$weights = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch all unique cat names for the filter dropdown
$stmt = $pdo->query("SELECT DISTINCT cat_name FROM weights ORDER BY cat_name ASC");
$allCatNames = $stmt->fetchAll(PDO::FETCH_COLUMN);


// Prepare data for Chart.js
// Create a map: cat_name -> { date -> weight }
$catWeightMap = [];
foreach ($weights as $entry) {
    if (!isset($catWeightMap[$entry['cat_name']])) {
        $catWeightMap[$entry['cat_name']] = [];
    }
    $catWeightMap[$entry['cat_name']][$entry['measure_date']] = $entry['weight'];
}

// Get all unique dates across all filtered cats for the x-axis labels
$allDates = [];
foreach ($weights as $entry) {
    $allDates[] = $entry['measure_date'];
}
$allDates = array_unique($allDates);
sort($allDates); // Ensure dates are in chronological order

$chartDatasets = [];
$colors = ['#4CAF50', '#2196F3', '#FFC107', '#E91E63', '#9C27B0', '#00BCD4', '#FF5722', '#673AB7']; // Example colors

$colorIndex = 0;
foreach ($catWeightMap as $catName => $data) {
    $datasetData = [];
    foreach ($allDates as $date) {
        // If a weight exists for this cat on this date, use it, otherwise null for gaps
        $datasetData[] = $data[$date] ?? null;
    }
    $chartDatasets[] = [
        'label' => htmlspecialchars($catName) . ' Weight (kg)',
        'data' => $datasetData,
        'borderColor' => $colors[$colorIndex % count($colors)],
        'tension' => 0.1,
        'fill' => false,
        'spanGaps' => true // Connects null values if there are gaps in data
    ];
    $colorIndex++;
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Cat Weight Tracker</title>
    <!-- Tailwind CSS CDN -->
    <script src="https://cdn.tailwindcss.com"></script>
    <!-- Inter font -->
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700&display=swap" rel="stylesheet">
    <!-- Chart.js CDN -->
    <script src="https://cdn.jsdelivr.net/npm/chart.js@3.7.0/dist/chart.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/chartjs-adapter-date-fns@3.0.0/dist/chartjs-adapter-date-fns.bundle.min.js"></script>
    <style>
        body {
            font-family: 'Inter', sans-serif;
            background-color: #f0f2f5;
        }
        .container {
            max-width: 960px;
        }
        /* Modal styles */
        .modal {
            position: fixed;
            z-index: 1000;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            overflow: auto;
            background-color: rgba(0,0,0,0.4);
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .modal-content {
            background-color: #fefefe;
            margin: auto;
            padding: 20px;
            border-radius: 0.5rem;
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1), 0 2px 4px -1px rgba(0, 0, 0, 0.06);
            width: 90%;
            max-width: 400px;
        }
    </style>
</head>
<body class="bg-gray-100 p-4">
    <div class="container mx-auto bg-white shadow-lg rounded-xl p-8 my-8">
        <h1 class="text-4xl font-bold text-center text-gray-800 mb-8">Cat Weight Tracker</h1>

        <?php if ($message): ?>
            <div class="rounded-lg p-4 mb-6
                <?php echo $messageType === 'success' ? 'bg-green-100 text-green-700 border border-green-400' : 'bg-red-100 text-red-700 border border-red-400'; ?>">
                <?php echo htmlspecialchars($message); ?>
            </div>
        <?php endif; ?>

        <!-- Add Weight Form -->
        <div class="mb-8 p-6 bg-gray-50 rounded-lg shadow-sm">
            <h2 class="text-2xl font-semibold text-gray-700 mb-4">Add/Update Weight Entry</h2>
            <form method="POST" class="grid grid-cols-1 md:grid-cols-4 gap-4">
                <input type="hidden" name="action" value="add_weight">
                <div>
                    <label for="cat_name" class="block text-sm font-medium text-gray-700">Cat's Name</label>
                    <input type="text" id="cat_name" name="cat_name" required
                           class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 p-2 border">
                </div>
                <div>
                    <label for="weight" class="block text-sm font-medium text-gray-700">Weight (kg)</label>
                    <input type="number" step="0.01" id="weight" name="weight" required
                           class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 p-2 border">
                </div>
                <div>
                    <label for="measure_date" class="block text-sm font-medium text-gray-700">Date</label>
                    <input type="date" id="measure_date" name="measure_date" required
                           class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 p-2 border">
                </div>
                <div class="flex items-end">
                    <button type="submit"
                            class="inline-flex justify-center py-2 px-6 border border-transparent shadow-sm text-sm font-medium rounded-md text-white bg-indigo-600 hover:bg-indigo-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500 transition duration-150 ease-in-out w-full">
                        Save Weight
                    </button>
                </div>
            </form>
        </div>

        <!-- Filter Options -->
        <div class="mb-8 p-6 bg-gray-50 rounded-lg shadow-sm">
            <h2 class="text-2xl font-semibold text-gray-700 mb-4">Filter History</h2>
            <form method="GET" class="grid grid-cols-1 md:grid-cols-4 gap-4 items-end">
                <div>
                    <label for="filter_cat_name" class="block text-sm font-medium text-gray-700">Filter by Cat</label>
                    <select id="filter_cat_name" name="filter_cat_name"
                            class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 p-2 border bg-white">
                        <option value="">All Cats</option>
                        <?php foreach ($allCatNames as $cat): ?>
                            <option value="<?php echo htmlspecialchars($cat); ?>"
                                <?php echo ($filterCatName === $cat) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($cat); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label for="start_date" class="block text-sm font-medium text-gray-700">Start Date</label>
                    <input type="date" id="start_date" name="start_date"
                           value="<?php echo htmlspecialchars($startDate); ?>"
                           class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 p-2 border">
                </div>
                <div>
                    <label for="end_date" class="block text-sm font-medium text-gray-700">End Date</label>
                    <input type="date" id="end_date" name="end_date"
                           value="<?php echo htmlspecialchars($endDate); ?>"
                           class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 p-2 border">
                </div>
                <div class="flex space-x-2">
                    <button type="submit"
                            class="inline-flex justify-center py-2 px-6 border border-transparent shadow-sm text-sm font-medium rounded-md text-white bg-blue-600 hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500 transition duration-150 ease-in-out flex-grow">
                        Apply Filter
                    </button>
                    <a href="index.php"
                       class="inline-flex justify-center py-2 px-6 border border-gray-300 shadow-sm text-sm font-medium rounded-md text-gray-700 bg-white hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500 transition duration-150 ease-in-out">
                        Clear Filter
                    </a>
                </div>
            </form>
        </div>


        <!-- Weight Trend Chart -->
        <div class="mb-8 p-6 bg-gray-50 rounded-lg shadow-sm">
            <h2 class="text-2xl font-semibold text-gray-700 mb-4">Weight Trend</h2>
            <div class="w-full h-80">
                <canvas id="weightChart"></canvas>
            </div>
        </div>

        <!-- Weight History Table -->
        <div class="p-6 bg-gray-50 rounded-lg shadow-sm">
            <h2 class="text-2xl font-semibold text-gray-700 mb-4">Weight History</h2>
            <?php if (empty($weights)): ?>
                <p class="text-gray-600">No weight entries yet or no entries match the current filter. Add some above!</p>
            <?php else: ?>
                <div class="overflow-x-auto rounded-lg border border-gray-200">
                    <table class="min-w-full divide-y divide-gray-200">
                        <thead class="bg-gray-100">
                            <tr>
                                <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider rounded-tl-lg">
                                    Cat Name
                                </th>
                                <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                    Weight (kg)
                                </th>
                                <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                    Date
                                </th>
                                <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider rounded-tr-lg">
                                    Actions
                                </th>
                            </tr>
                        </thead>
                        <tbody class="bg-white divide-y divide-gray-200">
                            <?php foreach ($weights as $entry): ?>
                                <tr>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900">
                                        <?php echo htmlspecialchars($entry['cat_name']); ?>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-700">
                                        <?php echo htmlspecialchars(number_format($entry['weight'], 2)); ?>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-700">
                                        <?php echo htmlspecialchars($entry['measure_date']); ?>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm font-medium">
                                        <button type="button"
                                                onclick="showDeleteModal(<?php echo $entry['id']; ?>, '<?php echo htmlspecialchars($entry['cat_name'] . ' on ' . $entry['measure_date']); ?>')"
                                                class="text-red-600 hover:text-red-900 transition duration-150 ease-in-out">
                                            Delete
                                        </button>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Delete Confirmation Modal -->
    <div id="deleteModal" class="modal hidden">
        <div class="modal-content">
            <h3 class="text-xl font-semibold text-gray-800 mb-4">Confirm Deletion</h3>
            <p class="text-gray-700 mb-6">Are you sure you want to delete the entry for <span id="entryToDeleteText" class="font-bold"></span>?</p>
            <div class="flex justify-end space-x-4">
                <button type="button" onclick="hideDeleteModal()"
                        class="py-2 px-4 border border-gray-300 rounded-md shadow-sm text-sm font-medium text-gray-700 bg-white hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500">
                    Cancel
                </button>
                <form method="POST" style="display:inline;">
                    <input type="hidden" name="action" value="delete_entry">
                    <input type="hidden" name="entry_id" id="confirmDeleteEntryId">
                    <button type="submit"
                            class="py-2 px-4 border border-transparent rounded-md shadow-sm text-sm font-medium text-white bg-red-600 hover:bg-red-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-red-500">
                        Delete
                    </button>
                </form>
            </div>
        </div>
    </div>

    <script>
        // Set today's date as default for the input field
        document.addEventListener('DOMContentLoaded', function() {
            const today = new Date();
            const yyyy = today.getFullYear();
            const mm = String(today.getMonth() + 1).padStart(2, '0'); // Months start at 0!
            const dd = String(today.getDate()).padStart(2, '0');
            const todayFormatted = `${yyyy}-${mm}-${dd}`;

            // Set default for add/update form
            const measureDateInput = document.getElementById('measure_date');
            if (measureDateInput && !measureDateInput.value) {
                measureDateInput.value = todayFormatted;
            }
        });

        const ctx = document.getElementById('weightChart').getContext('2d');
        const weightChart = new Chart(ctx, {
            type: 'line',
            data: {
                labels: <?php echo json_encode($allDates); ?>,
                datasets: <?php echo json_encode($chartDatasets); ?>
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        position: 'top',
                    },
                    title: {
                        display: true,
                        text: 'Cat Weight Over Time'
                    }
                },
                scales: {
                    x: {
                        type: 'time', // Use 'time' scale for dates
                        time: {
                            unit: 'day', // Display units as days
                            tooltipFormat: 'yyyy-MM-dd', // Format for tooltips
                            displayFormats: {
                                day: 'MMM d' // Format for axis labels
                            }
                        },
                        title: {
                            display: true,
                            text: 'Date'
                        }
                    },
                    y: {
                        beginAtZero: false,
                        title: {
                            display: true,
                            text: 'Weight (kg)'
                        }
                    }
                }
            }
        });

        // Modal functions
        const deleteModal = document.getElementById('deleteModal');
        const confirmDeleteEntryId = document.getElementById('confirmDeleteEntryId');
        const entryToDeleteText = document.getElementById('entryToDeleteText');

        function showDeleteModal(id, entryDescription) {
            confirmDeleteEntryId.value = id;
            entryToDeleteText.textContent = entryDescription;
            deleteModal.classList.remove('hidden');
        }

        function hideDeleteModal() {
            deleteModal.classList.add('hidden');
        }

        // Close modal if user clicks outside of it
        window.onclick = function(event) {
            if (event.target == deleteModal) {
                hideDeleteModal();
            }
        }

        // --- Debugging: Log chart data to console ---
        console.log("Chart Labels:", <?php echo json_encode($allDates); ?>);
        console.log("Chart Datasets:", <?php echo json_encode($chartDatasets); ?>);
        // --- End Debugging ---
    </script>
</body>
</html>
