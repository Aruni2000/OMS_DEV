<?php
// Get role name based on role_id from session
function getRoleName($role_id) {
    switch($role_id) {
        case 1:
            return 'admin';
        case 2:
            return 'user';
        case 3:
            return 'moderator';
        default:
            return 'user';
    }
}

// Get user data from session
$user_name = $_SESSION['name'] ?? 'User';
$user_email = $_SESSION['user'] ?? 'user@example.com';
$role_name = getRoleName($_SESSION['role_id'] ?? 2);
?>

<!-- [ Header Topbar ] start -->
<header class="pc-header">
  <div class="header-wrapper flex max-sm:px-[15px] px-[25px] grow">
    <!-- [Mobile Media Block] start -->
    <div class="me-auto pc-mob-drp">
      <ul class="inline-flex *:min-h-header-height *:inline-flex *:items-center">
        <!-- ======= Menu collapse Icon ===== -->
        <li class="pc-h-item pc-sidebar-collapse max-lg:hidden lg:inline-flex">
          <a href="#" class="pc-head-link ltr:!ml-0 rtl:!mr-0" id="sidebar-hide">
            <i data-feather="menu"></i>
          </a>
        </li>
        <li class="pc-h-item pc-sidebar-popup lg:hidden">
          <a href="#" class="pc-head-link ltr:!ml-0 rtl:!mr-0" id="mobile-collapse">
            <i data-feather="menu"></i>
          </a>
        </li>
        <li class="dropdown pc-h-item">
          <div class="dropdown-menu pc-h-dropdown drp-search">
            <form class="px-2 py-1">
              <input type="search" class="form-control !border-0 !shadow-none" placeholder="Search here. . ." />
            </form>
          </div>
        </li>
      </ul>
    </div>

    <!-- [Mobile Media Block end] -->
    <div class="ms-auto">
      <ul class="inline-flex *:min-h-header-height *:inline-flex *:items-center">
        <li class="dropdown pc-h-item header-user-profile">
          <a class="pc-head-link dropdown-toggle arrow-none me-0" data-pc-toggle="dropdown" href="#" role="button"
            aria-haspopup="false" data-pc-auto-close="outside" aria-expanded="false">
            <i data-feather="user"></i>
          </a>
          <div class="dropdown-menu dropdown-user-profile dropdown-menu-end pc-h-dropdown p-2 overflow-hidden">
            <div class="dropdown-header flex items-center justify-between py-4 px-5 bg-primary-500">
              <div class="flex mb-1 items-center">
                <div class="shrink-0">
                  <img src="../assets/images/user/avatar-2.jpg" alt="user-image" class="w-10 rounded-full" />
                </div>
                
                <div class="grow ms-3">
                  <h6 class="mb-1 text-white">
                    <?php echo htmlspecialchars($user_name); ?>
                  </h6>
                  <span class="text-white">
                    <?php echo htmlspecialchars($user_email); ?>
                  </span>
                  <div class="text-xs text-white/80 mt-1">
                    Role: <?php echo htmlspecialchars(ucfirst($role_name)); ?>
                  </div>
                </div>
              </div>
            </div>
            <div class="dropdown-body py-4 px-5">
              <div class="profile-notification-scroll position-relative" style="max-height: calc(100vh - 225px)">
                <a href='../pages/logout.php' class="dropdown-item">
                  <span>
                    <svg class="pc-icon text-muted me-2 inline-block">
                      <use xlink:href="#custom-setting-outline"></use>
                    </svg>
                    <span>Log Out</span>
                  </span>
                </a>
              </div>
            </div>
          </div>
        </li>
      </ul>
    </div>
  </div>
</header>
<!-- [ Header ] end -->