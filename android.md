# Optibiz Mobile Client Architecture
## Cross-Platform Mobile Strategy (Flutter & React Native)

**Document Purpose:** Enterprise-grade mobile architecture for Optibiz review and ratings platform  
**Target Personas:** Tenant Admin & End-User/Customer  
**Architecture Patterns:** Clean Architecture + MVVM + Riverpod/Bloc (Flutter) or Redux/Zustand (React Native)  
**Security Focus:** JWT/Bearer tokens, Biometric auth, Secure token storage  

---

## 1. Platform Selection Recommendation

### Flutter (Recommended for Optibiz)
**Advantages:**
- Single codebase for iOS, Android, and Web
- Superior performance and GPU rendering
- Riverpod for reactive state management (modern, simpler than Bloc)
- Excellent offline-first support
- Hot reload for rapid development

**Disadvantages:**
- Smaller ecosystem vs React Native
- Larger app bundle size (~25-40MB)

### React Native
**Advantages:**
- Larger ecosystem and community
- Easier to hire developers
- Code sharing with web frontend possible
- Smaller bundle size (~15-20MB)

**Disadvantages:**
- More performance considerations
- Larger native bridge overhead
- More platform-specific issues

**Recommendation:** **Flutter with Riverpod** for best performance, simplicity, and maintainability.

---

## 2. Complete App Folder Structure (Flutter)

```
optibiz_mobile/
├── android/                          # Native Android configuration
│   ├── app/
│   │   ├── build.gradle
│   │   └── src/main/AndroidManifest.xml
│   ├── gradle.properties
│   └── local.properties
├── ios/                              # Native iOS configuration
│   ├── Runner/
│   ├── Flutter/
│   └── Podfile
├── lib/
│   ├── main.dart                    # App entry point
│   ├── config/
│   │   ├── app_config.dart          # Environment configurations (dev, staging, prod)
│   │   ├── logger_config.dart       # Logging setup
│   │   └── routes.dart              # Route definitions
│   ├── core/
│   │   ├── constants/
│   │   │   ├── api_constants.dart   # API endpoints, timeout values
│   │   │   ├── app_constants.dart   # App-wide constants
│   │   │   └── string_constants.dart
│   │   ├── errors/
│   │   │   ├── app_exceptions.dart  # Custom exception classes
│   │   │   ├── error_handler.dart   # Centralized error handling
│   │   │   └── failure.dart         # Failure models for Result pattern
│   │   ├── extensions/
│   │   │   ├── build_context_ext.dart
│   │   │   ├── string_ext.dart
│   │   │   └── widget_ext.dart
│   │   ├── secure_storage/
│   │   │   ├── secure_storage_service.dart   # Encrypted token storage
│   │   │   └── flutter_secure_storage_impl.dart
│   │   ├── biometric/
│   │   │   ├── biometric_service.dart        # Face ID / Fingerprint
│   │   │   └── local_auth_impl.dart
│   │   ├── network/
│   │   │   ├── http_client.dart              # Dio client wrapper
│   │   │   ├── interceptors/
│   │   │   │   ├── auth_interceptor.dart     # JWT injection, refresh logic
│   │   │   │   ├── error_interceptor.dart
│   │   │   │   └── logging_interceptor.dart
│   │   │   └── api_response.dart             # Response models
│   │   └── utils/
│   │       ├── date_utils.dart
│   │       └── validators.dart
│   ├── data/
│   │   ├── datasources/
│   │   │   ├── local/
│   │   │   │   ├── app_database.dart        # Hive/Isar local DB
│   │   │   │   ├── user_local_datasource.dart
│   │   │   │   └── cache_manager.dart
│   │   │   └── remote/
│   │   │       ├── auth_remote_datasource.dart
│   │   │       ├── review_remote_datasource.dart
│   │   │       ├── company_remote_datasource.dart
│   │   │       └── payment_remote_datasource.dart
│   │   ├── models/
│   │   │   ├── auth/
│   │   │   │   ├── login_request.dart
│   │   │   │   ├── login_response.dart
│   │   │   │   ├── token_model.dart
│   │   │   │   └── refresh_token_request.dart
│   │   │   ├── user/
│   │   │   │   ├── user_model.dart
│   │   │   │   ├── tenant_admin_model.dart
│   │   │   │   └── customer_model.dart
│   │   │   ├── company/
│   │   │   │   ├── company_model.dart
│   │   │   │   ├── analytics_model.dart
│   │   │   │   └── qr_code_model.dart
│   │   │   ├── review/
│   │   │   │   ├── review_model.dart
│   │   │   │   ├── rating_submission_model.dart
│   │   │   │   └── feedback_model.dart
│   │   │   └── payment/
│   │   │       └── payment_model.dart
│   │   └── repositories/
│   │       ├── auth_repository_impl.dart
│   │       ├── user_repository_impl.dart
│   │       ├── company_repository_impl.dart
│   │       ├── review_repository_impl.dart
│   │       ├── qr_repository_impl.dart
│   │       └── payment_repository_impl.dart
│   ├── domain/
│   │   ├── entities/                        # Pure data entities
│   │   │   ├── auth_entity.dart
│   │   │   ├── user_entity.dart
│   │   │   ├── company_entity.dart
│   │   │   ├── review_entity.dart
│   │   │   └── payment_entity.dart
│   │   ├── repositories/                    # Abstract repositories
│   │   │   ├── auth_repository.dart
│   │   │   ├── user_repository.dart
│   │   │   ├── company_repository.dart
│   │   │   ├── review_repository.dart
│   │   │   ├── qr_repository.dart
│   │   │   └── payment_repository.dart
│   │   └── usecases/                        # Business logic
│   │       ├── auth/
│   │       │   ├── login_usecase.dart
│   │       │   ├── logout_usecase.dart
│   │       │   ├── refresh_token_usecase.dart
│   │       │   ├── biometric_login_usecase.dart
│   │       │   └── signup_usecase.dart
│   │       ├── company/
│   │       │   ├── get_company_analytics_usecase.dart
│   │       │   ├── toggle_review_booster_usecase.dart
│   │       │   └── get_qr_card_usecase.dart
│   │       ├── review/
│   │       │   ├── submit_rating_usecase.dart
│   │       │   ├── get_reviews_usecase.dart
│   │       │   └── get_feedback_usecase.dart
│   │       └── notification/
│   │           └── handle_push_notification_usecase.dart
│   ├── presentation/
│   │   ├── providers/                       # Riverpod providers
│   │   │   ├── auth_providers.dart
│   │   │   ├── user_providers.dart
│   │   │   ├── company_providers.dart
│   │   │   ├── review_providers.dart
│   │   │   ├── notification_providers.dart
│   │   │   └── theme_providers.dart
│   │   ├── screens/
│   │   │   ├── auth/
│   │   │   │   ├── login_screen.dart
│   │   │   │   ├── signup_screen.dart
│   │   │   │   ├── forgot_password_screen.dart
│   │   │   │   ├── biometric_setup_screen.dart
│   │   │   │   └── auth_controller.dart      # Riverpod StateNotifier
│   │   │   ├── tenant_admin/
│   │   │   │   ├── dashboard_screen.dart
│   │   │   │   ├── analytics_screen.dart
│   │   │   │   ├── reviews_management_screen.dart
│   │   │   │   ├── review_booster_screen.dart
│   │   │   │   ├── qr_card_generator_screen.dart
│   │   │   │   ├── notifications_screen.dart
│   │   │   │   └── settings_screen.dart
│   │   │   ├── customer/
│   │   │   │   ├── qr_scanner_screen.dart
│   │   │   │   ├── rating_submission_screen.dart
│   │   │   │   ├── feedback_screen.dart
│   │   │   │   └── thank_you_screen.dart
│   │   │   └── common/
│   │   │       ├── splash_screen.dart
│   │   │       ├── loading_screen.dart
│   │   │       └── error_screen.dart
│   │   ├── widgets/
│   │   │   ├── common/
│   │   │   │   ├── app_bar_custom.dart
│   │   │   │   ├── app_button.dart
│   │   │   │   ├── app_text_field.dart
│   │   │   │   ├── app_loading.dart
│   │   │   │   └── app_error_widget.dart
│   │   │   ├── tenant_admin/
│   │   │   │   ├── analytics_card.dart
│   │   │   │   ├── review_item.dart
│   │   │   │   ├── chart_widget.dart
│   │   │   │   └── qr_display_widget.dart
│   │   │   └── customer/
│   │   │       ├── rating_star_widget.dart
│   │   │       ├── feedback_form_widget.dart
│   │   │       └── success_widget.dart
│   │   └── theme/
│   │       ├── app_theme.dart
│   │       ├── text_styles.dart
│   │       └── colors.dart
│   ├── di/                                  # Dependency Injection
│   │   └── service_locator.dart             # GetIt or Riverpod setup
│   └── app.dart                          # Root widget
├── test/
│   ├── unit/
│   │   ├── usecases/
│   │   ├── repositories/
│   │   └── services/
│   └── integration/
│       ├── auth_flow_test.dart
│       └── review_submission_test.dart
├── pubspec.yaml                        # Dependencies
├── pubspec.lock
├── analysis_options.yaml               # Linter rules
└── README.md
```

---

## 3. State Management Pattern: Riverpod (Flutter)

### 3.1 Core Architecture Principles
```dart
// lib/presentation/providers/auth_providers.dart

import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:optibiz_mobile/domain/entities/auth_entity.dart';
import 'package:optibiz_mobile/domain/usecases/auth/login_usecase.dart';

// ============= STATE NOTIFIER DEFINITION =============
class AuthState {
  final bool isLoading;
  final bool isAuthenticated;
  final AuthEntity? user;
  final String? error;
  final bool isBiometricEnabled;

  AuthState({
    this.isLoading = false,
    this.isAuthenticated = false,
    this.user,
    this.error,
    this.isBiometricEnabled = false,
  });

  AuthState copyWith({
    bool? isLoading,
    bool? isAuthenticated,
    AuthEntity? user,
    String? error,
    bool? isBiometricEnabled,
  }) {
    return AuthState(
      isLoading: isLoading ?? this.isLoading,
      isAuthenticated: isAuthenticated ?? this.isAuthenticated,
      user: user ?? this.user,
      error: error ?? this.error,
      isBiometricEnabled: isBiometricEnabled ?? this.isBiometricEnabled,
    );
  }
}

// ============= STATE NOTIFIER CLASS =============
class AuthNotifier extends StateNotifier<AuthState> {
  final LoginUsecase _loginUsecase;
  final LogoutUsecase _logoutUsecase;
  final RefreshTokenUsecase _refreshTokenUsecase;
  final BiometricLoginUsecase _biometricLoginUsecase;

  AuthNotifier({
    required LoginUsecase loginUsecase,
    required LogoutUsecase logoutUsecase,
    required RefreshTokenUsecase refreshTokenUsecase,
    required BiometricLoginUsecase biometricLoginUsecase,
  })  : _loginUsecase = loginUsecase,
        _logoutUsecase = logoutUsecase,
        _refreshTokenUsecase = refreshTokenUsecase,
        _biometricLoginUsecase = biometricLoginUsecase,
        super(AuthState());

  // ============= LOGIN METHOD =============
  Future<void> login({
    required String email,
    required String password,
    bool rememberMe = false,
  }) async {
    state = state.copyWith(isLoading: true, error: null);
    
    final result = await _loginUsecase(
      email: email,
      password: password,
    );

    result.fold(
      (failure) {
        state = state.copyWith(
          isLoading: false,
          error: failure.message,
        );
      },
      (authEntity) {
        state = state.copyWith(
          isLoading: false,
          isAuthenticated: true,
          user: authEntity,
        );
        
        if (rememberMe) {
          _setupBiometric(authEntity);
        }
      },
    );
  }

  // ============= BIOMETRIC LOGIN METHOD =============
  Future<void> biometricLogin() async {
    state = state.copyWith(isLoading: true, error: null);
    
    final result = await _biometricLoginUsecase();

    result.fold(
      (failure) {
        state = state.copyWith(
          isLoading: false,
          error: failure.message,
        );
      },
      (authEntity) {
        state = state.copyWith(
          isLoading: false,
          isAuthenticated: true,
          user: authEntity,
        );
      },
    );
  }

  // ============= TOKEN REFRESH METHOD =============
  Future<void> refreshToken() async {
    final result = await _refreshTokenUsecase();

    result.fold(
      (failure) {
        // Force logout on refresh failure
        logout();
      },
      (authEntity) {
        state = state.copyWith(user: authEntity);
      },
    );
  }

  // ============= LOGOUT METHOD =============
  Future<void> logout() async {
    state = state.copyWith(isLoading: true);
    
    await _logoutUsecase();
    
    state = AuthState(); // Reset to initial state
  }

  Future<void> _setupBiometric(AuthEntity authEntity) async {
    // Setup biometric authentication for future logins
    // Store encrypted credentials in secure storage
  }
}

// ============= RIVERPOD PROVIDERS =============
final authNotifierProvider =
    StateNotifierProvider.autoDispose<AuthNotifier, AuthState>((ref) {
  final loginUsecase = ref.watch(loginUsecaseProvider);
  final logoutUsecase = ref.watch(logoutUsecaseProvider);
  final refreshTokenUsecase = ref.watch(refreshTokenUsecaseProvider);
  final biometricLoginUsecase = ref.watch(biometricLoginUsecaseProvider);

  return AuthNotifier(
    loginUsecase: loginUsecase,
    logoutUsecase: logoutUsecase,
    refreshTokenUsecase: refreshTokenUsecase,
    biometricLoginUsecase: biometricLoginUsecase,
  );
});

// Derived providers
final isAuthenticatedProvider = Provider<bool>((ref) {
  return ref.watch(authNotifierProvider).isAuthenticated;
});

final currentUserProvider = Provider<AuthEntity?>((ref) {
  return ref.watch(authNotifierProvider).user;
});

final authLoadingProvider = Provider<bool>((ref) {
  return ref.watch(authNotifierProvider).isLoading;
});
```

### 3.2 Repository Implementation Pattern
```dart
// lib/data/repositories/auth_repository_impl.dart

class AuthRepositoryImpl implements AuthRepository {
  final AuthRemoteDatasource remoteDatasource;
  final AuthLocalDatasource localDatasource;
  final SecureStorageService secureStorage;

  AuthRepositoryImpl({
    required this.remoteDatasource,
    required this.localDatasource,
    required this.secureStorage,
  });

  @override
  Future<Either<Failure, AuthEntity>> login({
    required String email,
    required String password,
  }) async {
    try {
      // 1. Call remote API
      final loginResponse = await remoteDatasource.login(
        email: email,
        password: password,
      );

      // 2. Parse response
      final tokenModel = loginResponse.tokenModel;
      final userModel = loginResponse.userModel;

      // 3. Store tokens securely
      await secureStorage.saveAccessToken(tokenModel.accessToken);
      await secureStorage.saveRefreshToken(tokenModel.refreshToken);

      // 4. Cache user locally
      await localDatasource.cacheUser(userModel);

      // 5. Return domain entity
      return Right(userModel.toDomain());
    } catch (e) {
      return Left(
        Failure(
          message: _parseError(e),
          statusCode: _extractStatusCode(e),
        ),
      );
    }
  }
}
```

---

## 4. Secure Authentication Flow Architecture

### 4.1 JWT Authentication with Token Refresh

```dart
// lib/core/network/interceptors/auth_interceptor.dart

class AuthInterceptor extends Interceptor {
  final SecureStorageService secureStorage;
  final RefreshTokenUsecase refreshTokenUsecase;

  AuthInterceptor({
    required this.secureStorage,
    required this.refreshTokenUsecase,
  });

  @override
  Future<void> onRequest(
    RequestOptions options,
    RequestInterceptorHandler handler,
  ) async {
    // 1. Inject access token in headers
    final accessToken = await secureStorage.getAccessToken();
    
    if (accessToken != null) {
      options.headers['Authorization'] = 'Bearer $accessToken';
    }

    handler.next(options);
  }

  @override
  Future<void> onError(
    DioException err,
    ErrorInterceptorHandler handler,
  ) async {
    // 2. Handle 401 Unauthorized
    if (err.response?.statusCode == 401) {
      try {
        // Attempt to refresh token
        final result = await refreshTokenUsecase();

        result.fold(
          (failure) {
            // Refresh failed - propagate 401
            handler.next(err);
          },
          (authEntity) {
            // Token refreshed - retry original request
            final options = err.requestOptions;
            options.headers['Authorization'] =
                'Bearer ${authEntity.accessToken}';

            final response = await Dio().request(
              options.path,
              options: options,
            );

            handler.resolve(response);
          },
        );
      } catch (e) {
        handler.next(err);
      }
    } else {
      handler.next(err);
    }
  }
}
```

### 4.2 Token Model and Storage
```dart
// lib/data/models/auth/token_model.dart

@freezed
class TokenModel with _$TokenModel {
  const factory TokenModel({
    required String accessToken,
    required String refreshToken,
    required DateTime expiresAt,
    required String tokenType,
  }) = _TokenModel;

  factory TokenModel.fromJson(Map<String, dynamic> json) =>
      _$TokenModelFromJson(json);
}

// lib/core/secure_storage/secure_storage_service.dart

abstract class SecureStorageService {
  Future<void> saveAccessToken(String token);
  Future<void> saveRefreshToken(String token);
  Future<void> saveCredentials({
    required String email,
    required String encryptedPassword,
  });
  
  Future<String?> getAccessToken();
  Future<String?> getRefreshToken();
  Future<Map<String, String>?> getCredentials();
  
  Future<void> deleteAll();
}

class FlutterSecureStorageImpl implements SecureStorageService {
  final FlutterSecureStorage _storage = const FlutterSecureStorage();

  @override
  Future<void> saveAccessToken(String token) async {
    await _storage.write(key: 'access_token', value: token);
  }

  @override
  Future<void> saveRefreshToken(String token) async {
    await _storage.write(key: 'refresh_token', value: token);
  }

  @override
  Future<String?> getAccessToken() async {
    return await _storage.read(key: 'access_token');
  }

  @override
  Future<String?> getRefreshToken() async {
    return await _storage.read(key: 'refresh_token');
  }

  @override
  Future<void> deleteAll() async {
    await _storage.deleteAll();
  }
}
```

### 4.3 Biometric Authentication

```dart
// lib/core/biometric/biometric_service.dart

abstract class BiometricService {
  Future<bool> isAvailable();
  Future<bool> authenticate({required String reason});
  Future<void> enrollBiometric();
}

class LocalAuthImpl implements BiometricService {
  final LocalAuthentication _localAuth = LocalAuthentication();

  @override
  Future<bool> isAvailable() async {
    try {
      return await _localAuth.canCheckBiometrics;
    } catch (e) {
      return false;
    }
  }

  @override
  Future<bool> authenticate({required String reason}) async {
    try {
      return await _localAuth.authenticate(
        localizedReason: reason,
        options: const AuthenticationOptions(
          stickyAuth: true,
          biometricOnly: true,
        ),
      );
    } catch (e) {
      return false;
    }
  }

  @override
  Future<void> enrollBiometric() async {
    // Guide user to enroll biometric in device settings
  }
}

// lib/domain/usecases/auth/biometric_login_usecase.dart

class BiometricLoginUsecase {
  final AuthRepository authRepository;
  final BiometricService biometricService;
  final SecureStorageService secureStorage;

  BiometricLoginUsecase({
    required this.authRepository,
    required this.biometricService,
    required this.secureStorage,
  });

  Future<Either<Failure, AuthEntity>> call() async {
    try {
      // 1. Authenticate with biometric
      final isAuthenticated = await biometricService.authenticate(
        reason: 'Authenticate to access your account',
      );

      if (!isAuthenticated) {
        return Left(Failure(message: 'Biometric authentication failed'));
      }

      // 2. Retrieve stored credentials
      final credentials = await secureStorage.getCredentials();
      if (credentials == null) {
        return Left(Failure(message: 'No stored credentials found'));
      }

      // 3. Perform login with stored credentials
      return await authRepository.login(
        email: credentials['email']!,
        password: credentials['password']!,
      );
    } catch (e) {
      return Left(Failure(message: e.toString()));
    }
  }
}
```

---

## 5. REST API Service Layer

### 5.1 HTTP Client Configuration

```dart
// lib/core/network/http_client.dart

class HttpClient {
  late Dio _dio;

  HttpClient({
    required String baseUrl,
    required List<Interceptor> interceptors,
  }) {
    final BaseOptions baseOptions = BaseOptions(
      baseUrl: baseUrl,
      connectTimeout: const Duration(seconds: 30),
      receiveTimeout: const Duration(seconds: 30),
      responseType: ResponseType.json,
      headers: {
        'Content-Type': 'application/json',
        'Accept': 'application/json',
      },
    );

    _dio = Dio(baseOptions);

    // Add interceptors
    _dio.interceptors.addAll(interceptors);
  }

  Future<T> get<T>({
    required String endpoint,
    Map<String, dynamic>? queryParameters,
    required T Function(dynamic) onSuccess,
  }) async {
    try {
      final response = await _dio.get(
        endpoint,
        queryParameters: queryParameters,
      );
      return onSuccess(response.data);
    } catch (e) {
      throw _handleError(e);
    }
  }

  Future<T> post<T>({
    required String endpoint,
    required Map<String, dynamic> body,
    required T Function(dynamic) onSuccess,
  }) async {
    try {
      final response = await _dio.post(
        endpoint,
        data: body,
      );
      return onSuccess(response.data);
    } catch (e) {
      throw _handleError(e);
    }
  }

  Future<T> put<T>({
    required String endpoint,
    required Map<String, dynamic> body,
    required T Function(dynamic) onSuccess,
  }) async {
    try {
      final response = await _dio.put(
        endpoint,
        data: body,
      );
      return onSuccess(response.data);
    } catch (e) {
      throw _handleError(e);
    }
  }

  Future<T> delete<T>({
    required String endpoint,
    required T Function(dynamic) onSuccess,
  }) async {
    try {
      final response = await _dio.delete(endpoint);
      return onSuccess(response.data);
    } catch (e) {
      throw _handleError(e);
    }
  }

  AppException _handleError(dynamic error) {
    if (error is DioException) {
      if (error.response != null) {
        return ApiException(
          message: error.response!.data['message'] ?? 'API Error',
          statusCode: error.response!.statusCode ?? 0,
        );
      }
      return NetworkException(message: error.message ?? 'Network Error');
    }
    return AppException(message: error.toString());
  }
}
```

### 5.2 Remote Datasources

```dart
// lib/data/datasources/remote/auth_remote_datasource.dart

abstract class AuthRemoteDatasource {
  Future<LoginResponseModel> login({
    required String email,
    required String password,
  });

  Future<TokenModel> refreshToken(String refreshToken);
  Future<void> logout(String accessToken);
}

class AuthRemoteDatasourceImpl implements AuthRemoteDatasource {
  final HttpClient httpClient;

  AuthRemoteDatasourceImpl({required this.httpClient});

  @override
  Future<LoginResponseModel> login({
    required String email,
    required String password,
  }) async {
    return await httpClient.post<LoginResponseModel>(
      endpoint: '/api/v1/auth/login',
      body: {
        'email': email,
        'password': password,
      },
      onSuccess: (data) => LoginResponseModel.fromJson(data),
    );
  }

  @override
  Future<TokenModel> refreshToken(String refreshToken) async {
    return await httpClient.post<TokenModel>(
      endpoint: '/api/v1/auth/refresh',
      body: {
        'refresh_token': refreshToken,
      },
      onSuccess: (data) => TokenModel.fromJson(data),
    );
  }

  @override
  Future<void> logout(String accessToken) async {
    await httpClient.post(
      endpoint: '/api/v1/auth/logout',
      body: {},
      onSuccess: (_) => null,
    );
  }
}

// lib/data/datasources/remote/company_remote_datasource.dart

abstract class CompanyRemoteDatasource {
  Future<CompanyAnalyticsModel> getAnalytics(String companyId);
  Future<void> toggleReviewBooster({
    required String companyId,
    required bool enabled,
  });
  Future<QRCodeModel> generateQRCard(String companyId);
}

class CompanyRemoteDatasourceImpl implements CompanyRemoteDatasource {
  final HttpClient httpClient;

  CompanyRemoteDatasourceImpl({required this.httpClient});

  @override
  Future<CompanyAnalyticsModel> getAnalytics(String companyId) async {
    return await httpClient.get<CompanyAnalyticsModel>(
      endpoint: '/api/v1/company/$companyId/analytics',
      onSuccess: (data) => CompanyAnalyticsModel.fromJson(data),
    );
  }

  @override
  Future<void> toggleReviewBooster({
    required String companyId,
    required bool enabled,
  }) async {
    await httpClient.put(
      endpoint: '/api/v1/company/$companyId/review-booster',
      body: {'enabled': enabled},
      onSuccess: (_) => null,
    );
  }

  @override
  Future<QRCodeModel> generateQRCard(String companyId) async {
    return await httpClient.get<QRCodeModel>(
      endpoint: '/api/v1/company/$companyId/qr-card',
      onSuccess: (data) => QRCodeModel.fromJson(data),
    );
  }
}

// lib/data/datasources/remote/review_remote_datasource.dart

abstract class ReviewRemoteDatasource {
  Future<ReviewResponseModel> submitRating({
    required String companyId,
    required int rating,
    required String feedback,
  });

  Future<List<ReviewModel>> getReviews(String companyId);
  Future<void> submitCustomerFeedback({
    required String reviewId,
    required String feedback,
  });
}

class ReviewRemoteDatasourceImpl implements ReviewRemoteDatasource {
  final HttpClient httpClient;

  ReviewRemoteDatasourceImpl({required this.httpClient});

  @override
  Future<ReviewResponseModel> submitRating({
    required String companyId,
    required int rating,
    required String feedback,
  }) async {
    return await httpClient.post<ReviewResponseModel>(
      endpoint: '/api/v1/review/submit',
      body: {
        'company_id': companyId,
        'rating': rating,
        'feedback': feedback,
      },
      onSuccess: (data) => ReviewResponseModel.fromJson(data),
    );
  }

  @override
  Future<List<ReviewModel>> getReviews(String companyId) async {
    return await httpClient.get<List<ReviewModel>>(
      endpoint: '/api/v1/review/company/$companyId',
      onSuccess: (data) => (data as List)
          .map((item) => ReviewModel.fromJson(item))
          .toList(),
    );
  }

  @override
  Future<void> submitCustomerFeedback({
    required String reviewId,
    required String feedback,
  }) async {
    await httpClient.post(
      endpoint: '/api/v1/review/$reviewId/feedback',
      body: {
        'feedback': feedback,
      },
      onSuccess: (_) => null,
    );
  }
}
```

### 5.3 API Response Models

```dart
// lib/data/models/api_response.dart

@freezed
class ApiResponse<T> with _$ApiResponse<T> {
  const factory ApiResponse({
    required bool success,
    required T data,
    String? message,
    int? statusCode,
  }) = _ApiResponse;

  factory ApiResponse.fromJson(
    Map<String, dynamic> json,
    T Function(Object?) fromJsonT,
  ) =>
      _$ApiResponseFromJson(json, fromJsonT);
}

// lib/data/models/auth/login_response.dart

@freezed
class LoginResponseModel with _$LoginResponseModel {
  const factory LoginResponseModel({
    required TokenModel tokenModel,
    required UserModel userModel,
  }) = _LoginResponseModel;

  factory LoginResponseModel.fromJson(Map<String, dynamic> json) =>
      _$LoginResponseModelFromJson(json);
}

// lib/data/models/review/review_model.dart

@freezed
class ReviewModel with _$ReviewModel {
  const factory ReviewModel({
    required String id,
    required String companyId,
    required int rating,
    String? feedback,
    required DateTime createdAt,
    String? reviewUrl, // Link to Google Review or Internal Feedback
  }) = _ReviewModel;

  factory ReviewModel.fromJson(Map<String, dynamic> json) =>
      _$ReviewModelFromJson(json);

  ReviewEntity toDomain() {
    return ReviewEntity(
      id: id,
      companyId: companyId,
      rating: rating,
      feedback: feedback,
      createdAt: createdAt,
      reviewUrl: reviewUrl,
    );
  }
}

@freezed
class ReviewResponseModel with _$ReviewResponseModel {
  const factory ReviewResponseModel({
    required bool success,
    required String reviewId,
    required int rating,
    String? redirectUrl, // URL to Google Review or Feedback Form
    String? message,
  }) = _ReviewResponseModel;

  factory ReviewResponseModel.fromJson(Map<String, dynamic> json) =>
      _$ReviewResponseModelFromJson(json);
}
```

---

## 6. API Endpoint Integration Reference

### Expected PHP Backend Endpoints

```
Authentication:
  POST   /api/v1/auth/login                  # {email, password}
  POST   /api/v1/auth/refresh                # {refresh_token}
  POST   /api/v1/auth/logout                 # {}
  POST   /api/v1/auth/signup                 # {email, password, company_id}

Tenant Admin:
  GET    /api/v1/company/{id}/analytics      # Returns: {reviews_count, avg_rating, ...}
  GET    /api/v1/company/{id}/reviews        # Returns: [reviews]
  PUT    /api/v1/company/{id}/review-booster # {enabled}
  GET    /api/v1/company/{id}/qr-card        # Returns: {qr_data, download_url}
  GET    /api/v1/notification/all            # Returns: [notifications]

Customer / End-User:
  POST   /api/v1/review/submit               # {company_id, rating, feedback}
  POST   /api/v1/review/{id}/feedback        # {feedback}

Push Notifications:
  POST   /api/v1/notification/register       # {device_token, platform}
  POST   /api/v1/notification/send           # Internal: Send push notification
```

---

## 7. Dependency Injection Setup

```dart
// lib/di/service_locator.dart

final getIt = GetIt.instance;

void setupServiceLocator() {
  // ============= CORE SERVICES =============
  getIt.registerSingleton<SecureStorageService>(
    FlutterSecureStorageImpl(),
  );

  getIt.registerSingleton<BiometricService>(
    LocalAuthImpl(),
  );

  // ============= HTTP CLIENT =============
  getIt.registerSingleton<HttpClient>(
    HttpClient(
      baseUrl: const String.fromEnvironment(
        'API_BASE_URL',
        defaultValue: 'https://api.optibiz.local',
      ),
      interceptors: [
        getIt<AuthInterceptor>(),
        LoggingInterceptor(),
        ErrorInterceptor(),
      ],
    ),
  );

  // ============= REMOTE DATASOURCES =============
  getIt.registerSingleton<AuthRemoteDatasource>(
    AuthRemoteDatasourceImpl(httpClient: getIt()),
  );

  getIt.registerSingleton<CompanyRemoteDatasource>(
    CompanyRemoteDatasourceImpl(httpClient: getIt()),
  );

  getIt.registerSingleton<ReviewRemoteDatasource>(
    ReviewRemoteDatasourceImpl(httpClient: getIt()),
  );

  // ============= REPOSITORIES =============
  getIt.registerSingleton<AuthRepository>(
    AuthRepositoryImpl(
      remoteDatasource: getIt(),
      localDatasource: getIt(),
      secureStorage: getIt(),
    ),
  );

  getIt.registerSingleton<CompanyRepository>(
    CompanyRepositoryImpl(
      remoteDatasource: getIt(),
      localDatasource: getIt(),
    ),
  );

  getIt.registerSingleton<ReviewRepository>(
    ReviewRepositoryImpl(
      remoteDatasource: getIt(),
      localDatasource: getIt(),
    ),
  );

  // ============= USE CASES =============
  getIt.registerSingleton<LoginUsecase>(
    LoginUsecase(repository: getIt()),
  );

  getIt.registerSingleton<LogoutUsecase>(
    LogoutUsecase(repository: getIt()),
  );

  getIt.registerSingleton<RefreshTokenUsecase>(
    RefreshTokenUsecase(repository: getIt()),
  );

  getIt.registerSingleton<BiometricLoginUsecase>(
    BiometricLoginUsecase(
      authRepository: getIt(),
      biometricService: getIt(),
      secureStorage: getIt(),
    ),
  );

  getIt.registerSingleton<SubmitRatingUsecase>(
    SubmitRatingUsecase(repository: getIt()),
  );

  getIt.registerSingleton<GetCompanyAnalyticsUsecase>(
    GetCompanyAnalyticsUsecase(repository: getIt()),
  );
}
```

---

## 8. UI Layer - Login Screen Example

```dart
// lib/presentation/screens/auth/login_screen.dart

class LoginScreen extends ConsumerWidget {
  const LoginScreen({Key? key}) : super(key: key);

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final emailController = TextEditingController();
    final passwordController = TextEditingController();
    final rememberMeNotifier = ref.watch(rememberMeProvider);
    final authState = ref.watch(authNotifierProvider);
    final authNotifier = ref.read(authNotifierProvider.notifier);

    return Scaffold(
      appBar: AppBar(title: const Text('Login')),
      body: Padding(
        padding: const EdgeInsets.all(16.0),
        child: Column(
          mainAxisAlignment: MainAxisAlignment.center,
          children: [
            // Email TextField
            AppTextField(
              controller: emailController,
              label: 'Email',
              keyboardType: TextInputType.emailAddress,
            ),
            const SizedBox(height: 16),

            // Password TextField
            AppTextField(
              controller: passwordController,
              label: 'Password',
              obscureText: true,
            ),
            const SizedBox(height: 16),

            // Remember Me Checkbox
            CheckboxListTile(
              title: const Text('Remember me'),
              value: rememberMeNotifier,
              onChanged: (value) {
                ref.read(rememberMeProvider.notifier).state = value!;
              },
            ),
            const SizedBox(height: 24),

            // Login Button
            authState.isLoading
                ? const CircularProgressIndicator()
                : AppButton(
                    label: 'Login',
                    onPressed: () async {
                      await authNotifier.login(
                        email: emailController.text,
                        password: passwordController.text,
                        rememberMe: rememberMeNotifier,
                      );

                      if (authState.isAuthenticated && context.mounted) {
                        _navigateToDashboard(context);
                      }
                    },
                  ),
            const SizedBox(height: 12),

            // Biometric Login Button (conditionally shown)
            FutureBuilder<bool>(
              future: ref.read(biometricServiceProvider).isAvailable(),
              builder: (context, snapshot) {
                if (snapshot.data == true) {
                  return AppButton(
                    label: 'Biometric Login',
                    onPressed: () async {
                      await authNotifier.biometricLogin();
                      if (authState.isAuthenticated && context.mounted) {
                        _navigateToDashboard(context);
                      }
                    },
                  );
                }
                return const SizedBox();
              },
            ),

            // Error Display
            if (authState.error != null)
              Padding(
                padding: const EdgeInsets.only(top: 16.0),
                child: Text(
                  authState.error!,
                  style: const TextStyle(color: Colors.red),
                ),
              ),
          ],
        ),
      ),
    );
  }

  void _navigateToDashboard(BuildContext context) {
    final userRole = context.read(authNotifierProvider).user?.role;
    if (userRole == 'tenant_admin') {
      Navigator.pushReplacementNamed(context, '/admin-dashboard');
    } else {
      Navigator.pushReplacementNamed(context, '/customer-qr-scanner');
    }
  }
}
```

---

## 9. Tenant Admin Dashboard - Analytics Screen

```dart
// lib/presentation/screens/tenant_admin/analytics_screen.dart

class AnalyticsScreen extends ConsumerWidget {
  const AnalyticsScreen({Key? key}) : super(key: key);

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final currentUser = ref.watch(currentUserProvider);
    final companyId = currentUser?.companyId ?? '';

    final analyticsAsyncValue =
        ref.watch(companyAnalyticsProvider(companyId));

    return Scaffold(
      appBar: AppBar(title: const Text('Analytics')),
      body: analyticsAsyncValue.when(
        loading: () => const Center(child: CircularProgressIndicator()),
        error: (error, stackTrace) => Center(child: Text('Error: $error')),
        data: (analytics) => SingleChildScrollView(
          padding: const EdgeInsets.all(16),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              // Average Rating Card
              AnalyticsCard(
                title: 'Average Rating',
                value: analytics.averageRating.toStringAsFixed(1),
                subtitle: '${analytics.totalReviews} reviews',
              ),
              const SizedBox(height: 16),

              // Rating Distribution Chart
              Text(
                'Rating Distribution',
                style: Theme.of(context).textTheme.headlineSmall,
              ),
              ChartWidget(distribution: analytics.ratingDistribution),
              const SizedBox(height: 24),

              // Review Booster Toggle
              Card(
                child: ListTile(
                  title: const Text('Google Review Booster'),
                  subtitle: const Text('Route 4-5 stars to Google Reviews'),
                  trailing: Switch(
                    value: analytics.reviewBoosterEnabled,
                    onChanged: (value) {
                      ref
                          .read(toggleReviewBoosterProvider(companyId).future)
                          .then((_) {
                        // Refresh analytics
                        ref.refresh(companyAnalyticsProvider(companyId));
                      });
                    },
                  ),
                ),
              ),
              const SizedBox(height: 16),

              // QR Card Generator
              AppButton(
                label: 'Generate QR Card',
                onPressed: () {
                  Navigator.pushNamed(
                    context,
                    '/qr-card-generator',
                    arguments: companyId,
                  );
                },
              ),
            ],
          ),
        ),
      ),
    );
  }
}

// Riverpod Provider
final companyAnalyticsProvider =
    FutureProvider.family<CompanyAnalyticsEntity, String>((ref, companyId) {
  final repository = ref.watch(companyRepositoryProvider);
  return repository.getAnalytics(companyId);
});
```

---

## 10. Customer Rating Submission Flow

```dart
// lib/presentation/screens/customer/rating_submission_screen.dart

class RatingSubmissionScreen extends ConsumerStatefulWidget {
  final String companyId;

  const RatingSubmissionScreen({
    Key? key,
    required this.companyId,
  }) : super(key: key);

  @override
  ConsumerState<RatingSubmissionScreen> createState() =>
      _RatingSubmissionScreenState();
}

class _RatingSubmissionScreenState
    extends ConsumerState<RatingSubmissionScreen> {
  int selectedRating = 0;
  final feedbackController = TextEditingController();

  @override
  Widget build(BuildContext context) {
    final reviewState = ref.watch(reviewNotifierProvider);

    return Scaffold(
      appBar: AppBar(title: const Text('Rate Your Experience')),
      body: Padding(
        padding: const EdgeInsets.all(16.0),
        child: Column(
          mainAxisAlignment: MainAxisAlignment.center,
          children: [
            // Star Rating Widget
            RatingStarWidget(
              rating: selectedRating,
              onRatingChanged: (rating) {
                setState(() => selectedRating = rating);
              },
            ),
            const SizedBox(height: 32),

            // Feedback TextField (show only for low ratings)
            if (selectedRating > 0 && selectedRating <= 3)
              Material(
                child: TextField(
                  controller: feedbackController,
                  decoration: InputDecoration(
                    hintText: 'Tell us how we can improve...',
                    border: OutlineInputBorder(
                      borderRadius: BorderRadius.circular(8),
                    ),
                  ),
                  maxLines: 4,
                ),
              ),
            const SizedBox(height: 32),

            // Submit Button
            reviewState.isLoading
                ? const CircularProgressIndicator()
                : AppButton(
                    label: 'Submit Rating',
                    onPressed: selectedRating == 0
                        ? null
                        : () => _submitRating(),
                  ),

            // Error Display
            if (reviewState.error != null)
              Padding(
                padding: const EdgeInsets.only(top: 16.0),
                child: Text(
                  reviewState.error!,
                  style: const TextStyle(color: Colors.red),
                ),
              ),
          ],
        ),
      ),
    );
  }

  Future<void> _submitRating() async {
    final reviewNotifier = ref.read(reviewNotifierProvider.notifier);

    await reviewNotifier.submitRating(
      companyId: widget.companyId,
      rating: selectedRating,
      feedback: feedbackController.text,
    );

    if (mounted && ref.read(reviewNotifierProvider).error == null) {
      // Route based on rating
      Navigator.pop(context);
      if (selectedRating >= 4) {
        // Show success and route to Google Reviews
        _showGoogleReviewRedirect();
      } else {
        // Show thank you for internal feedback
        Navigator.pushNamed(context, '/thank-you');
      }
    }
  }

  void _showGoogleReviewRedirect() {
    // Implementation to open Google Review link
  }

  @override
  void dispose() {
    feedbackController.dispose();
    super.dispose();
  }
}
```

---

## 11. Push Notifications Setup

```dart
// lib/core/notifications/notification_service.dart

class NotificationService {
  final FirebaseMessaging _firebaseMessaging = FirebaseMessaging.instance;

  Future<void> initialize() async {
    // Request permission (iOS)
    await _firebaseMessaging.requestPermission();

    // Get device token
    final deviceToken = await _firebaseMessaging.getToken();

    // Register token on backend
    await _registerDeviceToken(deviceToken!);

    // Listen for foreground messages
    FirebaseMessaging.onMessage.listen((RemoteMessage message) {
      _handleForegroundMessage(message);
    });

    // Listen for background messages
    FirebaseMessaging.onMessageOpenedApp.listen((RemoteMessage message) {
      _handleBackgroundMessage(message);
    });
  }

  Future<void> _registerDeviceToken(String token) async {
    final httpClient = getIt<HttpClient>();
    await httpClient.post(
      endpoint: '/api/v1/notification/register',
      body: {
        'device_token': token,
        'platform': Platform.isAndroid ? 'android' : 'ios',
      },
      onSuccess: (_) => null,
    );
  }

  void _handleForegroundMessage(RemoteMessage message) {
    // Show local notification
    // Navigate to relevant screen
  }

  void _handleBackgroundMessage(RemoteMessage message) {
    // Handle notification tap
  }
}
```

---

## 12. pubspec.yaml Dependencies

```yaml
name: optibiz_mobile
description: Optibiz Review and Ratings Platform - Mobile Client
publish_to: 'none'

version: 1.0.0+1

environment:
  sdk: '>=3.0.0 <4.0.0'

dependencies:
  flutter:
    sdk: flutter

  # State Management
  flutter_riverpod: ^2.4.0
  riverpod_annotation: ^2.3.0

  # HTTP & Networking
  dio: ^5.3.0
  retrofit: ^4.0.0

  # Serialization
  freezed_annotation: ^2.4.0
  json_serializable: ^6.7.0

  # Authentication
  flutter_secure_storage: ^9.0.0
  local_auth: ^2.1.0
  jwt_decoder: ^2.0.1

  # Database
  isar: ^3.1.0

  # Push Notifications
  firebase_core: ^2.24.0
  firebase_messaging: ^14.7.0

  # QR Code
  qr_flutter: ^4.0.0
  mobile_scanner: ^3.4.4

  # Logging & Monitoring
  logger: ^2.0.0
  sentry_flutter: ^7.10.0

  # Utilities
  get_it: ^7.6.0
  intl: ^0.19.0
  connectivity_plus: ^5.0.0

  # UI
  cupertino_icons: ^1.0.2

dev_dependencies:
  flutter_test:
    sdk: flutter

  flutter_lints: ^2.0.0
  build_runner: ^2.4.0
  freezed: ^2.4.0
  retrofit_generator: ^7.0.0
  json_serializable: ^6.7.0

flutter:
  uses-material-design: true
```

---

## 13. Environment Configuration

```dart
// lib/config/app_config.dart

enum Environment { production, staging, development }

class AppConfig {
  static const String _productionUrl = 'https://api.optibiz.com';
  static const String _stagingUrl = 'https://staging-api.optibiz.com';
  static const String _devUrl = 'http://localhost:8000';

  static late Environment _environment;

  static void setEnvironment(Environment env) {
    _environment = env;
  }

  static String get baseUrl {
    switch (_environment) {
      case Environment.production:
        return _productionUrl;
      case Environment.staging:
        return _stagingUrl;
      case Environment.development:
        return _devUrl;
    }
  }

  static bool get isProduction => _environment == Environment.production;
  static bool get isStaging => _environment == Environment.staging;
  static bool get isDevelopment => _environment == Environment.development;

  static Duration get connectTimeout => const Duration(seconds: 30);
  static Duration get receiveTimeout => const Duration(seconds: 30);
}

// main.dart
void main() {
  const environment = String.fromEnvironment('ENVIRONMENT', defaultValue: 'development');
  AppConfig.setEnvironment(
    environment == 'production'
        ? Environment.production
        : environment == 'staging'
            ? Environment.staging
            : Environment.development,
  );

  setupServiceLocator();
  runApp(const ProviderScope(child: MyApp()));
}
```

---

## 14. Security Best Practices

### 14.1 Token Refresh Strategy
- **Auto-refresh:** Intercept 401 responses and refresh token before retrying
- **Proactive refresh:** Refresh token 5 minutes before expiry
- **Secure storage:** Use platform-native secure storage (Keychain/Keystore)
- **No hardcoded keys:** Use environment variables for API keys

### 14.2 API Request Signing
```dart
// lib/core/network/interceptors/signature_interceptor.dart

class SignatureInterceptor extends Interceptor {
  final String apiSecret;

  SignatureInterceptor({required this.apiSecret});

  @override
  Future<void> onRequest(
    RequestOptions options,
    RequestInterceptorHandler handler,
  ) async {
    final timestamp = DateTime.now().millisecondsSinceEpoch.toString();
    final signature = _generateSignature(
      method: options.method,
      path: options.path,
      body: options.data,
      timestamp: timestamp,
    );

    options.headers['X-Timestamp'] = timestamp;
    options.headers['X-Signature'] = signature;

    handler.next(options);
  }

  String _generateSignature({
    required String method,
    required String path,
    required dynamic body,
    required String timestamp,
  }) {
    final message = '$method$path${jsonEncode(body)}$timestamp';
    return Hmac(sha256, utf8.encode(apiSecret))
        .convert(utf8.encode(message))
        .toString();
  }
}
```

### 14.3 Certificate Pinning
```dart
// lib/core/network/http_client.dart (updated)

SecurityContext _createSecurityContext() {
  final context = SecurityContext.defaultContext;
  
  // Pin specific SSL certificate
  context.setTrustedCertificatesBytes(
    File('assets/certs/optibiz_cert.pem').readAsBytesSync(),
  );
  
  return context;
}
```

---

## 15. Testing Strategy

### 15.1 Unit Tests
```dart
// test/unit/usecases/login_usecase_test.dart

void main() {
  group('LoginUsecase', () {
    late LoginUsecase loginUsecase;
    late MockAuthRepository mockAuthRepository;

    setUp(() {
      mockAuthRepository = MockAuthRepository();
      loginUsecase = LoginUsecase(repository: mockAuthRepository);
    });

    test('should return AuthEntity on successful login', () async {
      final tEmail = 'test@example.com';
      final tPassword = 'password123';
      final tAuthEntity = AuthEntity(
        id: '1',
        email: tEmail,
        role: 'tenant_admin',
        accessToken: 'access_token',
        refreshToken: 'refresh_token',
      );

      when(mockAuthRepository.login(
        email: tEmail,
        password: tPassword,
      )).thenAnswer((_) async => Right(tAuthEntity));

      final result = await loginUsecase(email: tEmail, password: tPassword);

      expect(result, Right(tAuthEntity));
      verify(mockAuthRepository.login(
        email: tEmail,
        password: tPassword,
      )).called(1);
    });
  });
}
```

### 15.2 Integration Tests
```dart
// test/integration/auth_flow_test.dart

void main() {
  group('Authentication Flow Integration', () {
    late WidgetTester tester;

    setUpAll(() async {
      setupServiceLocator();
    });

    testWidgets('User can login and navigate to dashboard', (
      WidgetTester tester,
    ) async {
      await tester.pumpWidget(const MyApp());

      // Find and enter email
      await tester.enterText(
        find.byType(TextField).first,
        'admin@optibiz.com',
      );

      // Find and enter password
      await tester.enterText(
        find.byType(TextField).last,
        'password123',
      );

      // Tap login button
      await tester.tap(find.byType(AppButton));

      // Wait for navigation
      await tester.pumpAndSettle();

      // Verify we're on dashboard
      expect(find.byType(DashboardScreen), findsOneWidget);
    });
  });
}
```

---

## 16. Deployment & CI/CD

### 16.1 GitHub Actions Example
```yaml
# .github/workflows/mobile-release.yml

name: Mobile Release Build

on:
  push:
    branches: [main]
  tags:
    - 'v*'

jobs:
  build:
    runs-on: ubuntu-latest
    
    steps:
      - uses: actions/checkout@v3
      
      - name: Setup Flutter
        uses: subosito/flutter-action@v2
        with:
          flutter-version: '3.13.0'
      
      - name: Get dependencies
        run: flutter pub get
      
      - name: Run tests
        run: flutter test
      
      - name: Build APK
        run: flutter build apk --release --split-per-abi
      
      - name: Build iOS
        run: flutter build ios --release
      
      - name: Upload artifacts
        uses: actions/upload-artifact@v3
        with:
          name: release-builds
          path: |
            build/app/outputs/apk/release/
            build/ios/iphoneos/
```

---

## 17. Monitoring & Analytics

```dart
// lib/core/monitoring/analytics_service.dart

class AnalyticsService {
  final FirebaseAnalytics _analytics = FirebaseAnalytics.instance;

  Future<void> logLogin({required String userId, required String role}) async {
    await _analytics.logLogin(
      loginMethod: 'email',
    );
    await _analytics.logEvent(
      name: 'user_login',
      parameters: {
        'user_id': userId,
        'role': role,
      },
    );
  }

  Future<void> logRatingSubmission({
    required String companyId,
    required int rating,
  }) async {
    await _analytics.logEvent(
      name: 'rating_submitted',
      parameters: {
        'company_id': companyId,
        'rating': rating,
      },
    );
  }

  Future<void> logError({required String error, required String stackTrace}) async {
    await Sentry.captureException(
      Exception(error),
      stackTrace: stackTrace,
    );
  }
}
```

---

## 18. Next Steps & Roadmap

1. **Phase 1 (MVP - 8 weeks)**
   - Core authentication with JWT
   - Basic Tenant Admin dashboard
   - Customer QR scanner + rating submission
   - Push notifications

2. **Phase 2 (Enhanced Features - 6 weeks)**
   - Offline mode with local data sync
   - Advanced analytics with charts
   - Biometric login
   - Payment integration

3. **Phase 3 (Optimization - 4 weeks)**
   - Performance optimization
   - App store/Play store submission
   - Monitoring and analytics dashboard
   - A/B testing framework

---

## 19. Key Libraries & Tools

| Purpose | Library | Version |
|---------|---------|---------|
| State Management | Riverpod | 2.4.0+ |
| HTTP Client | Dio | 5.3.0+ |
| Serialization | Freezed | 2.4.0+ |
| Secure Storage | flutter_secure_storage | 9.0.0+ |
| Biometric | local_auth | 2.1.0+ |
| Database | Isar | 3.1.0+ |
| Push Notifications | Firebase Messaging | 14.7.0+ |
| QR Code | qr_flutter | 4.0.0+ |
| Dependency Injection | GetIt | 7.6.0+ |
| Logging | Logger | 2.0.0+ |
| Error Tracking | Sentry | 7.10.0+ |

---

## 20. Conclusion

This architecture provides:

✅ **Clear separation of concerns** (Domain → Data → Presentation)  
✅ **Reactive state management** with Riverpod  
✅ **Secure authentication** with JWT refresh and biometric login  
✅ **Comprehensive API integration** with interceptors  
✅ **Offline-first support** with local caching  
✅ **Scalable folder structure** for team collaboration  
✅ **Production-ready** with monitoring, error handling, and testing  

The mobile client is designed to seamlessly integrate with your existing PHP/MySQL backend while providing a world-class user experience for both Tenant Admins and Customers.

---

**Document Version:** 1.0  
**Last Updated:** September 16, 2026  
**Architecture Lead:** Lead Mobile Architect
