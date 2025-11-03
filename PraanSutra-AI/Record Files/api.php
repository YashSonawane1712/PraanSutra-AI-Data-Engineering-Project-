<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *'); 
header('Access-Control-Allow-Methods: GET');

$servername = "127.0.0.1";
$username = "root";
$password = "";
$dbname = "clinical_db";
$port = 3307;

function get_simulated_patients($conn) {
    $sql = "SELECT id, name, age, gender, disease, base_risk_probability, last_appointment_date FROM patients"; 
    $result = $conn->query($sql);
    $patients = [];

    if ($result->num_rows > 0) {
        while($row = $result->fetch_assoc()) {
            $current_risk = (float)$row['base_risk_probability'];
            
            $risk_fluctuation = (mt_rand() / mt_getrandmax() * 0.1) - 0.05;
            $new_risk = max(0.1, min(1.0, $current_risk + $risk_fluctuation));
            
            $patients[] = [
                'name' => $row['name'],
                'age' => (int)$row['age'],
                'gender' => $row['gender'],
                'disease' => $row['disease'],
                'risk' => round($new_risk, 2),
                'isUpdated' => (mt_rand(1, 100) <= 20)
            ];
        }
    }
    return $patients;
}

function get_risk_by_age($conn) {
    $sql = "
        SELECT
            CASE
                WHEN age < 30 THEN '< 30'
                WHEN age BETWEEN 30 AND 49 THEN '30-49'
                WHEN age BETWEEN 50 AND 69 THEN '50-69'
                ELSE '70+'
            END AS age_group,
            AVG(base_risk_probability) AS avg_risk
        FROM patients
        GROUP BY age_group
        ORDER BY age_group;
    ";
    $result = $conn->query($sql);
    
    $age_groups = ['< 30', '30-49', '50-69', '70+'];
    $risk_scores = array_fill_keys($age_groups, 0.0);
    
    if ($result->num_rows > 0) {
        while($row = $result->fetch_assoc()) {
            $risk_scores[$row['age_group']] = (float)$row['avg_risk'];
        }
    }
    
    return [
        'labels' => array_values($age_groups),
        'scores' => array_values($risk_scores)
    ];
}

function get_risk_by_disease($conn) {
    $sql = "
        SELECT 
            disease, 
            COUNT(*) AS total_count
        FROM patients
        GROUP BY disease
        ORDER BY total_count DESC;
    ";
    $result = $conn->query($sql);
    
    $labels = [];
    $counts = [];
    
    if ($result->num_rows > 0) {
        while($row = $result->fetch_assoc()) {
            $labels[] = $row['disease'];
            $counts[] = (int)$row['total_count'];
        }
    }
    
    return [
        'labels' => $labels,
        'counts' => $counts
    ];
}

function get_monthly_appointment_volume($conn) {
    $appointment_sql = "
        SELECT 
            DATE_FORMAT(last_appointment_date, '%b') AS month_label,
            MONTH(last_appointment_date) AS month_num,
            YEAR(last_appointment_date) AS year_num,
            COUNT(id) AS count
        FROM patients
        WHERE last_appointment_date IS NOT NULL
        AND last_appointment_date >= DATE_SUB(CURDATE(), INTERVAL 12 MONTH)
        GROUP BY 1, 2, 3
        ORDER BY year_num ASC, month_num ASC
    ";
    
    $result = $conn->query($appointment_sql);
    $monthly_counts = [];
    
    if ($result->num_rows > 0) {
        while($row = $result->fetch_assoc()) {
            $monthly_counts[] = $row;
        }
    }
    
    $labels = [];
    $data = [];
    $today = new DateTime();
    
    for ($i = 11; $i >= 0; $i--) {
        $date = (clone $today)->modify("-$i month");
        $month_label = $date->format('M');
        $month_num = (int)$date->format('n');
        $year_num = (int)$date->format('Y');

        $labels[] = $month_label . ' ' . $year_num; 
        
        $count = 0;
        foreach ($monthly_counts as $row) {
            if ((int)$row['month_num'] === $month_num && (int)$row['year_num'] === $year_num) {
                $count = (int)$row['count'];
                break;
            }
        }
        $data[] = $count;
    }

    return [
        'labels' => $labels,
        'counts' => $data
    ];
}


$response_data = [];
try {
    $conn = new mysqli($servername, $username, $password, $dbname, $port);

    if ($conn->connect_error) {
        throw new Exception("Connection failed: " . $conn->connect_error);
    }
    
    $patients = get_simulated_patients($conn);
    $risk_by_age = get_risk_by_age($conn);
    $risk_by_disease = get_risk_by_disease($conn);
    $appointment_volume = get_monthly_appointment_volume($conn); 
    
    $conn->close();
    
    $response_data = [
        'patients' => $patients,
        'analysis' => [
            'riskByAge' => $risk_by_age,
            'riskByDisease' => $risk_by_disease,
            'appointmentVolume' => $appointment_volume
        ]
    ];
    
    echo json_encode($response_data);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(["error" => $e->getMessage()]);
}
?>