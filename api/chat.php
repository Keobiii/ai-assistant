<?php

header("Content-Type: application/json");

/* =========================
   DB CONNECTION
========================= */
$conn = new mysqli("localhost", "root", "", "company");

if ($conn->connect_error) {
    die(json_encode(array("error" => "DB connection failed")));
}

/* =========================
   USER INPUT (PHP 5 SAFE)
========================= */
$userMessage = isset($_POST['message']) ? $_POST['message'] : '';

/* =========================
   OLLAMA REQUEST
========================= */
$url = "http://localhost:11434/api/generate";

$ollamaPayload = array(
    "model" => "llama3",
    "prompt" => <<<EOT
You are an HR assistant system.

STRICT RULES:
- You ONLY return JSON
- NEVER explain
- NEVER use normal text
- ALWAYS return a valid "type": "action"

---

AVAILABLE ACTIONS:

1. query_attendance
   - used for attendance records
   - filters:
     - status: absent | late | present
     - date: today | yesterday

2. count_employees
   - used for counting employees
   - filters:
     - status: regular | resigned | contractual | probationary | all
     - department: HR | IT | Finance | Sales | all
     - position: any job title or all

---

DECISION RULES:

- "how many employees" → all
- "how many employees in HR / IT / Finance" → department
- "how many resigned employees" → status
- "how many contractual employees in IT" → status + department
- "how many employees per position" → position

---

OUTPUT FORMAT:

{
  "type": "action",
  "action": "query_attendance OR count_employees",
  "filters": {
    "status": "based on request",
    "date": "only if needed"
  }
}

User question:
$userMessage
EOT,
    "stream" => false
);

$options = array(
    "http" => array(
        "header"  => "Content-Type: application/json",
        "method"  => "POST",
        "content" => json_encode($ollamaPayload),
    )
);

$context = stream_context_create($options);
$response = file_get_contents($url, false, $context);

if ($response === false) {
    echo json_encode(array("error" => "Failed to connect to Ollama"));
    exit;
}

/* =========================
   PARSE AI RESPONSE
========================= */
$result = json_decode($response, true);

$aiOutput = isset($result['response']) ? trim($result['response']) : '';

$data = json_decode($aiOutput, true);

/* =========================
   VALIDATION
========================= */
if (!$data || !isset($data['type'])) {
    echo json_encode(array(
        "error" => "Invalid AI JSON",
        "raw" => $aiOutput
    ));
    exit;
}

/* =========================
   ACTION HANDLER
========================= */
if ($data['type'] == 'action') {

    /* =========================
       ATTENDANCE HANDLER
    ========================= */
    if ($data['action'] == 'query_attendance') {

        $status = isset($data['filters']['status']) ? $data['filters']['status'] : '';
        $date = isset($data['filters']['date']) ? $data['filters']['date'] : '';

        $status = mysqli_real_escape_string($conn, $status);

        /* =========================
           DATE HANDLING
        ========================= */
        if ($date == "today") {
            $dateCondition = "CURDATE()";
        } elseif ($date == "yesterday") {
            $dateCondition = "DATE_SUB(CURDATE(), INTERVAL 1 DAY)";
        } else {
            $dateCondition = "'" . mysqli_real_escape_string($conn, $date) . "'";
        }

        /* =========================
           QUERY
        ========================= */
        $sql = "
            SELECT *
            FROM attendance
            WHERE status = '$status'
            AND date = $dateCondition
        ";

        $resultQuery = mysqli_query($conn, $sql);

        $employees = array();

        if ($resultQuery) {
            while ($row = mysqli_fetch_assoc($resultQuery)) {
                $employees[] = $row;
            }
        }

        /* =========================
           HUMAN RESPONSE (PHP ONLY)
        ========================= */
        $count = count($employees);
        $statusText = ucfirst($status);
        $dateText = $date;

        if ($count == 0) {
            $message = "No employees marked as $statusText for $dateText.";
        } elseif ($count == 1) {
            $message = "There is 1 employee marked as $statusText for $dateText.";
        } else {
            $message = "There are $count employees marked as $statusText for $dateText.";
        }

        echo json_encode(array(
            "message" => $message,
            "count" => $count,
            "employees" => $employees
        ));

        exit;
    }

    if ($data['action'] == 'count_employees') {

      $status = isset($data['filters']['status']) ? $data['filters']['status'] : 'all';
      $department = isset($data['filters']['department']) ? $data['filters']['department'] : 'all';
      $position = isset($data['filters']['position']) ? $data['filters']['position'] : 'all';

      $sql = "SELECT COUNT(*) as total FROM employees WHERE 1=1";

      /* =========================
        STATUS FILTER
      ========================= */
      if ($status != '' && $status != 'all') {
          $status = mysqli_real_escape_string($conn, $status);
          $sql .= " AND status = '$status'";
      }

      /* =========================
        DEPARTMENT FILTER
      ========================= */
      if ($department != '' && $department != 'all') {
          $department = mysqli_real_escape_string($conn, $department);
          $sql .= " AND department = '$department'";
      }

      /* =========================
        POSITION FILTER
      ========================= */
      if ($position != '' && $position != 'all') {
          $position = mysqli_real_escape_string($conn, $position);
          $sql .= " AND position = '$position'";
      }

      $result = mysqli_query($conn, $sql);
      $row = mysqli_fetch_assoc($result);

      $count = $row['total'];

      echo json_encode(array(
          "message" => "Total employees: $count",
          "count" => $count
      ));

      exit;
    }
}


/* =========================
   FALLBACK OUTPUT
========================= */
echo json_encode($data);