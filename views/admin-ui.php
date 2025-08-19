<?php
if (!defined('pp_allowed_access')) {
    die('Direct access not allowed');
}

global $conn, $setting, $auth_id;
global $db_host, $db_user, $db_pass, $db_name, $db_prefix, $mode;

$plugin_slug = 'transactions-import-uddoktapay';
$settings = pp_get_plugin_setting($plugin_slug);
$setting = pp_get_settings();

// Get the plugin directory URL
$plugin_dir = dirname(__DIR__);
$plugin_url = str_replace($_SERVER['DOCUMENT_ROOT'], '', $plugin_dir);

// Function to dynamically find pp-config.php
function find_pp_config(): ?string
{
    $start = __DIR__;
    for ($i = 0; $i < 8; $i++) {
        $root = dirname($start, $i + 1);
        $cfg = $root . '/pp-config.php';
        if (is_file($cfg) && is_readable($cfg)) {
            return realpath($cfg);
        }
    }
    return null;
}

// Find and include the configuration file
$config_path = find_pp_config();
if ($config_path === null) {
    die('Could not find pp-config.php file');
}

require_once $config_path;

// Enable error reporting for debugging
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Try updating database
if (!isset($conn)) {
    $conn = new mysqli($db_host, $db_user, $db_pass, $db_name);
    if ($conn->connect_error) {
        $error = "Connection failed: " . $conn->connect_error;
    }
    if (!$conn->query("SET NAMES utf8")) {
        $error = "Set names failed: " . $conn->error;
    }
    if (!empty($db_prefix)) {
        if (!$conn->query("SET sql_mode = ''")) {
            $error = "Set sql_mode failed: " . $conn->error;
        }
    }
}

if (isset($conn) && !$conn->connect_error) {
    $auth_id = uniqid();
    $sql = "UPDATE {$db_prefix}plugins SET plugin_array = '{\"auth_id\":\"$auth_id\"}' WHERE plugin_slug = '{$plugin_slug}'";
    $result = $conn->query($sql);
}

// Fetch active payment gateway plugins
$payment_methods = [];
if (isset($conn) && !$conn->connect_error) {
    $sql = "SELECT * FROM {$db_prefix}plugins WHERE status = 'active' AND plugin_dir = 'payment-gateway' AND JSON_extract(plugin_array, '$.status') = 'enable'";
    $result = $conn->query($sql);

    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $plugin_array = json_decode($row['plugin_array'], true);
            $payment_methods[] = [
                'name' => $row['plugin_name'],
                'slug' => $row['plugin_slug'],
                'currency' => isset($plugin_array['currency']) ? $plugin_array['currency'] : null
            ];
        }
    }
}

?>

<?php if (isset($message)) {
    echo $message;
} ?>

<div class="d-flex flex-column gap-4">
  <!-- Page Header -->
  <div class="page-header">
    <div class="row align-items-end">
      <div class="col-sm mb-2 mb-sm-0 d-flex align-items-center gap-2">
        <h1 class="page-header-title" style="margin-bottom: 4px;">Transactions Import From UddoktaPay</h1>
      </div>
      <div class="col-sm-auto">
        <button type="button" class="btn btn-primary" id="refreshData">
          <i class="bi bi-arrow-clockwise"></i> Reset Data
        </button>
      </div>
    </div>
  </div>
  <div class="row justify-content-center">
    <div class="col-md-8">
      <div class="card">
        <div class="card-header">
          <h5 class="card-title">Import JSON Transactions</h5>
        </div>
        <div class="card-body">
          <form id="jsonUploadForm">
            <div class="mb-3">
            <div class="f-flex flex-column gap-2 mb-3">
            <p class="form-label fw-bold">Follow the specific steps to get the JSON file from your old UddoktaPay Database</p>
              <p class="form-label">1. Go to Phpmyadmin</p>
              <p class="form-label">2. Select your old UddoktaPay Database</p>
              <p class="form-label">3. Then select Payments table</p>
              <p class="form-label">4. Click on Export</p>
              <p class="form-label">5. Then change the format to JSON</p>
              <p class="form-label">6. Then click export and save the Payments JSON File</p>
              <p class="form-label">6. Upload the JSON file here</p>
            </div>
              <input type="file" class="form-control" id="jsonFile" accept=".json" required>
              <div class="form-text">Please upload Payment's table JSON file containing transaction data.</div>
            </div>
            <button type="submit" class="btn btn-primary">Process JSON</button>
          </form>
          <div id="jsonResults" class="mt-4"></div>
        </div>
      </div>
      <div id="payment-method-mapping"></div>
      <div id="import-data"></div>
    </div>
  </div>

  <script>
    // JSON Processing Function
    function processJSON(file) {
      const reader = new FileReader();

      reader.onload = function(event) {
        const jsonData = JSON.parse(event.target.result);
        const paymentTable = jsonData.find((element) => element.type === 'table' && element.name === 'payments');
        const paymentData = paymentTable.data;
        delete paymentData.id;
        delete paymentData.brand_id;
        delete paymentData.invoice_id;
        delete paymentData.payment_id;

        // Extract unique payment methods
        const uniquePaymentMethods = [...new Set(Object.values(paymentData).map(payment => payment.payment_method))];

        // Function to update dropdown options
        function updateDropdownOptions() {
          const selects = document.querySelectorAll('.payment-method-select');
          const selectedValues = Array.from(selects).map(select => select.value).filter(value => value !== '');

          selects.forEach(select => {
            const currentValue = select.value;
            const options = select.querySelectorAll('option');

            options.forEach(option => {
              if (option.value === '' || option.value === currentValue) {
                option.disabled = false;
              } else {
                option.disabled = selectedValues.includes(option.value);
              }
            });
          });
        }

        // Create UI for payment methods
        const paymentMethodMapping = document.getElementById('payment-method-mapping');
        paymentMethodMapping.innerHTML = `
             <div class="mt-4">
                <div class="card">
                  <div class="card-header">
                    <h5 class="card-title">Payment Method Mapping</h5>
                  </div>
                  <div class="card-body">
                    <div class="table-responsive">
                      <table class="table">
                        <thead>
                          <tr>
                            <th>Uddoktapay Payment Method</th>
                            <th>PipraPay Payment Method</th>
                          </tr>
                        </thead>
                        <tbody>
                         ${uniquePaymentMethods.filter(method => method != null).map(method => `
                           <tr>
                              <td>${method}</td>
                              <td>
                              <select class="form-select payment-method-select" data-original="${method}">
                                <option value="">Select Payment Method</option>
                                <?php foreach ($payment_methods as $pm): ?>
                                <option data-slug="<?php echo htmlspecialchars($pm['slug']); ?>" data-name="<?php echo htmlspecialchars($pm['name']); ?>" data-currency="<?php echo htmlspecialchars($pm['currency']); ?>"><?php echo htmlspecialchars($pm['name']); ?></option>
                                <?php endforeach; ?>
                              </select>
                              </td>
                            </tr>
                        `).join('')}
                        </tbody>
                      </table>
                    </div>
                    <button class="btn btn-primary mt-3" id="applyMapping">Apply Mapping</button>
                  </div>
                </div>
              </div>
          `;

        // Add change event listeners to all dropdowns
        document.querySelectorAll('.payment-method-select').forEach(select => {
          select.addEventListener('change', updateDropdownOptions);
        });

        // Initial update of dropdown options
        updateDropdownOptions();

        // Handle payment method mapping
        document.getElementById('applyMapping').addEventListener('click', function() {
          // Disable the Apply Mapping button and change its text
          const applyMappingButton = this;
          applyMappingButton.disabled = true;
          applyMappingButton.innerHTML = '<i class="bi bi-arrow-right"></i> Go to Import Section';

          // Disable all dropdown selects
          document.querySelectorAll('.payment-method-select').forEach(select => {
            select.disabled = true;
          });

          const mappings = {};

          // Collect mappings
          document.querySelectorAll('.payment-method-select').forEach(select => {
            const originalMethod = select.dataset.original;
            const selectedOption = select.options[select.selectedIndex];
            const newSlug = selectedOption.dataset.slug;
            const newName = selectedOption.dataset.name;
            const newCurrency = selectedOption.dataset.currency;
            if (newSlug && newName) {
              mappings[originalMethod] = {
                slug: newSlug,
                name: newName,
                currency: newCurrency
              };
            }
          });

          // Create a copy of payment data with updated mappings
          const mappedPaymentData = Object.values(paymentData).map(payment => {
            const mapping = mappings[payment.payment_method];
            const paymentCopy = {
              ...payment
            };

            if (mapping) {
              paymentCopy.payment_method_id = mapping.slug; // keep slug in id
              paymentCopy.payment_method = mapping.name; // replace display name
              paymentCopy.transaction_currency = mapping.currency; // replace currency
            }

            return paymentCopy;
          });

          const formatNumberFromText = (text) => {
            // Extract only digits
            let number = text.replace(/\D/g, "");

            // If less than 10, add random digits until length = 10
            while (number.length < 10) {
              number += Math.floor(Math.random() * 10); // add random digit (0–9)
            }

            // If longer than 12, trim to 12
            if (number.length > 12) {
              number = number.slice(0, 12);
            }

            return number;
          }

          const makeMediaUrl = (path) => {
            // Get only the filename from the path
            const filename = path.split('/').pop();
            // Get current host from window
            const host = window.location.host
            // Build the full URL
            return `${window.location.protocol}//${host}/pp-external/media/${filename}`;
          }

          const roundStringNumber = (str) => {
            const num = parseFloat(str); // convert string to number
            return Math.round(num).toString();
          }

          const normalizeNumber = (str) => {
            const num = parseFloat(str); // convert string to number
            return num === 0 ? "0" : num.toString();
          }

          const finalData = mappedPaymentData
            .filter(payment => (payment.payment_method_id && payment.payment_method !== null && (payment.status ==
              'Completed' || payment.status === 'Pending')))
            .map(payment => {
              const pp_id = formatNumberFromText(payment?.payment_id);
              const data = {
                pp_id: pp_id,
                c_id: '--',
                c_name: payment?.full_name,
                c_email_mobile: payment?.email,
                payment_method_id: payment?.payment_method_id,
                payment_method: payment?.payment_method,
                payment_verify_way: payment?.payment_slip ? 'slip' : 'id',
                payment_sender_number: payment?.sender_number ? payment.sender_number : '--',
                payment_verify_id: payment?.payment_slip ? makeMediaUrl(payment.payment_slip) : payment
                  .transaction_id,
                transaction_amount: roundStringNumber(payment?.amount),
                transaction_fee: normalizeNumber(payment?.fee),
                transaction_refund_amount: normalizeNumber(payment?.refunded_amount),
                transaction_refund_reason: payment?.refund_notes ? payment.refund_notes : '--',
                transaction_currency: payment?.transaction_currency,
                transaction_redirect_url: JSON.parse(payment?.metadata)?.payment_type == 'Invoice' ? '--' :
                  payment?.redirect_url,
                transaction_return_type: payment?.return_type?.toUpperCase(),
                transaction_cancel_url: payment?.cancel_url ? payment.cancel_url : '--',
                transaction_webhook_url: payment?.webhook_url ? payment.webhook_url : '--',
                transaction_metadata: payment?.metadata,
                transaction_status: payment?.status?.toLowerCase(),
                transaction_product_name: '--',
                transaction_product_description: '--',
                transaction_product_meta: '--',
                created_at: payment?.date
              }

              return data;
            });

          // Create and show the import card
          const importCard = document.createElement('div');
          importCard.className = 'mt-4';
          importCard.innerHTML = `
            <div class="card">
              <div class="card-header">
                <h5 class="card-title">Import Data in the Database</h5>
              </div>
              <div class="card-body">
                <button class="btn btn-primary" id="startImport">Start Import</button>
              </div>
            </div>
          `;

          // Add the import card after the mapping card
          const importDataElement = document.getElementById('import-data');
          while (importDataElement.firstChild) {
            importDataElement.removeChild(importDataElement.firstChild);
          }
          importDataElement.appendChild(importCard);

          // Add click event listener for the Start Import button
          document.getElementById('startImport').addEventListener('click', function() {
            const importButton = this;
            const cardBody = importButton.closest('.card-body');

            // Disable button and show loading state
            importButton.disabled = true;
            importButton.innerHTML =
              '<span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span> Importing...';

            // Remove any existing error messages
            const existingAlert = cardBody.querySelector('.alert');
            if (existingAlert) {
              existingAlert.remove();
            }

            // Send JSON array to PHP
            fetch("<?php echo $plugin_url; ?>/views/insert.php", {
                method: "POST",
                headers: {
                  "Content-Type": "application/json"
                },
                body: JSON.stringify({ data: finalData, auth_id: "<?php echo $auth_id; ?>" })
              })
              .then(res => res.json())
              .then(response => {
                if (response.status === 'success') {
                  // Remove the button
                  importButton.remove();

                  // Show response message
                  const alertClass = response.status === 'success' ? 'alert-success' : 'alert-danger';
                  const message = `
                    <div class="alert ${alertClass}" role="alert">
                      ${response.status === 'success' 
                        ? `Successfully imported ${response.inserted} out of ${response.total} records.`
                        : `Error: ${response.message}`}
                    </div>`;
                  cardBody.innerHTML = message;
                } else {
                  // Show error message but keep the button
                  importButton.disabled = false;
                  importButton.innerHTML = 'Start Import';

                  const errorAlert = document.createElement('div');
                  errorAlert.className = 'alert alert-danger mt-3';
                  errorAlert.textContent = response.message ||
                    'An error occurred during import. Please try again.';
                  cardBody.appendChild(errorAlert);
                }
              })
              .catch(error => {
                // Handle any fetch or parsing errors
                importButton.disabled = false;
                importButton.innerHTML = 'Start Import';

                const errorAlert = document.createElement('div');
                errorAlert.className = 'alert alert-danger mt-3';
                errorAlert.textContent = 'An error occurred during import. Please try again.';
                cardBody.appendChild(errorAlert);

                console.error(error);
              });

            // console.log('Updated payment data:', finalData);
          });
        });

      };

      reader.onerror = function() {
        console.error('Error reading JSON file');
      };

      reader.readAsText(file);
    }

    // Handle form submission
    document.getElementById('jsonUploadForm').addEventListener('submit', function(e) {
      e.preventDefault();
      const fileInput = document.getElementById('jsonFile');
      const submitButton = this.querySelector('button[type="submit"]');
      const file = fileInput.files[0];

      if (file) {
        if (file.type === 'application/json' || file.name.endsWith('.json')) {
          // Disable both the file input and submit button
          fileInput.disabled = true;
          submitButton.disabled = true;
          submitButton.innerHTML = '<i class="bi bi-arrow-right"></i> Go to Mapping Section';

          processJSON(file);
        } else {
          document.getElementById('jsonResults').innerHTML =
            '<div class="alert alert-danger">Please upload a valid JSON file</div>';
        }
      }
    });

    // Handle refresh button click
    document.getElementById('refreshData').addEventListener('click', function() {
      // Add loading state
      const button = this;
      const originalContent = button.innerHTML;
      button.innerHTML = '<i class="bi bi-arrow-clockwise"></i> Loading...';
      button.disabled = true;

      // Reload the page
      window.location.reload();
    });
  </script>
  <style>
    /* Make disabled options look gray */
    .payment-method-select option:disabled {
      color: red;
      /* or any color */
    }
  </style>

  <!-- Assets are loaded via WordPress enqueue functions -->