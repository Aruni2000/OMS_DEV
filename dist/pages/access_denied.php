<?php
// Start session at the very beginning
session_start();

include($_SERVER['DOCUMENT_ROOT'] . '/OMS/dist/include/navbar.php');
include($_SERVER['DOCUMENT_ROOT'] . '/OMS/dist/include/sidebar.php');
?>

<!doctype html>
<html lang="en" data-pc-preset="preset-1" data-pc-sidebar-caption="true" data-pc-direction="ltr" dir="ltr" data-pc-theme="light">

<head>
    <!-- TITLE -->
    <title>Access Denied - Order Management Admin Portal</title>

    <?php
    include($_SERVER['DOCUMENT_ROOT'] . '/OMS/dist/include/head.php');
    ?>
    
    <!-- [Template CSS Files] -->
    <link rel="stylesheet" href="../assets/css/style.css" id="main-style-link" />
    <link rel="stylesheet" href="../assets/css/customers.css" id="main-style-link" />
</head>

<body>
    <!-- LOADER -->
    <?php
        include($_SERVER['DOCUMENT_ROOT'] . '/OMS/dist/include/loader.php');
    ?>
    <!-- END LOADER -->

    <!-- [ Main Content ] start -->
    <div class="pc-container">
        <div class="pc-content">
            <!-- [ breadcrumb ] start -->
            <div class="page-header">
                <div class="page-block">
                    <div class="page-header-title">
                        <h5 class="mb-0 font-medium">Access Denied</h5>
                    </div>
                </div>
            </div>
            <!-- [ breadcrumb ] end -->

            <!-- [ Main Content ] start -->
            <div class="main-container">
                <!-- Access Denied Message -->
                <div class="access-denied-container">
                    <div class="access-denied-content">
                        <div class="access-denied-icon">
                            <i class="fas fa-exclamation-triangle"></i>
                        </div>
                        <div class="access-denied-message">
                            <h3>Access Denied</h3>
                            <p>You do not have access to this page.</p>
                            <!-- <p>Please contact your administrator if you believe this is an error.</p> -->
                        </div>
                        <div class="access-denied-actions">
                            <a href="javascript:history.back()" class="btn btn-secondary">
                                <i class="fas fa-arrow-left"></i> Go Back
                            </a>
                            <a href='/OMS/dist/dashboard/index.php' class="btn btn-primary">
                                <i class="fas fa-home"></i> Go to Dashboard
                            </a>
                        </div>
                    </div>
                </div>
            </div>
            <!-- [ Main Content ] end -->
        </div>
    </div>

    <!-- FOOTER -->
    <?php
    include($_SERVER['DOCUMENT_ROOT'] . '/OMS/dist/include/footer.php');
    ?>
    <!-- END FOOTER -->

    <!-- SCRIPTS -->
    <?php
    include($_SERVER['DOCUMENT_ROOT'] . '/OMS/dist/include/scripts.php');
    ?>
    <!-- END SCRIPTS -->

    <style>
        .access-denied-container {
            display: flex;
            justify-content: center;
            align-items: center;
            min-height: 60vh;
            padding: 2rem;
        }

        .access-denied-content {
            text-align: center;
            background: #fff;
            padding: 3rem;
            border-radius: 10px;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
            max-width: 500px;
            width: 100%;
        }

        .access-denied-icon {
            margin-bottom: 1.5rem;
        }

        .access-denied-icon i {
            font-size: 4rem;
            color: #dc3545;
        }

        .access-denied-message h3 {
            color: #dc3545;
            margin-bottom: 1rem;
            font-size: 1.5rem;
            font-weight: 600;
        }

        .access-denied-message p {
            color: #6c757d;
            margin-bottom: 0.5rem;
            line-height: 1.5;
        }

        .access-denied-actions {
            margin-top: 2rem;
            display: flex;
            gap: 1rem;
            justify-content: center;
            flex-wrap: wrap;
        }

        .access-denied-actions .btn {
            padding: 0.75rem 1.5rem;
            border-radius: 5px;
            text-decoration: none;
            font-weight: 500;
            transition: all 0.3s ease;
        }

        .access-denied-actions .btn-primary {
            background-color: #007bff;
            border-color: #007bff;
            color: white;
        }

        .access-denied-actions .btn-primary:hover {
            background-color: #0056b3;
            border-color: #0056b3;
        }

        .access-denied-actions .btn-secondary {
            background-color: #6c757d;
            border-color: #6c757d;
            color: white;
        }

        .access-denied-actions .btn-secondary:hover {
            background-color: #545b62;
            border-color: #545b62;
        }

        @media (max-width: 576px) {
            .access-denied-content {
                padding: 2rem 1.5rem;
            }
            
            .access-denied-actions {
                flex-direction: column;
                align-items: center;
            }
            
            .access-denied-actions .btn {
                width: 100%;
                max-width: 200px;
            }
        }
    </style>

</body>
</html>