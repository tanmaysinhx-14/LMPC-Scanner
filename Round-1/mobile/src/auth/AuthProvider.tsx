import * as SecureStore from "expo-secure-store";
import { createContext, useContext, useEffect, useMemo, useState, type PropsWithChildren } from "react";
import { civicConnectApi, setAuthCredentials, setAuthPersistence } from "../api/client";
import type { User } from "../api/types";

const TOKEN_KEY = "civicconnect.mobile.access-token";
const REFRESH_KEY = "civicconnect.mobile.refresh-token";

type AuthContextValue = {
  ready: boolean;
  token: string | null;
  user: User | null;
  login: (email: string, password: string, role?: string) => Promise<User>;
  logout: () => Promise<void>;
};

const AuthContext = createContext<AuthContextValue | null>(null);

export function AuthProvider({ children }: PropsWithChildren) {
  const [ready, setReady] = useState(false);
  const [token, setTokenState] = useState<string | null>(null);
  const [user, setUser] = useState<User | null>(null);

  useEffect(() => {
    setAuthPersistence(async (credentials) => {
      await SecureStore.setItemAsync(TOKEN_KEY, credentials.access_token);
      await SecureStore.setItemAsync(REFRESH_KEY, credentials.refresh_token);
    });
    let mounted = true;
    void (async () => {
      const storedToken = await SecureStore.getItemAsync(TOKEN_KEY);
      const storedRefreshToken = await SecureStore.getItemAsync(REFRESH_KEY);
      if (!mounted) return;
      if (storedToken && storedRefreshToken) {
        setAuthCredentials(storedToken, storedRefreshToken);
        try {
          const result = await civicConnectApi.getMe();
          if (mounted) {
            setTokenState(storedToken);
            setUser(result.data.user);
          }
        } catch {
          await SecureStore.deleteItemAsync(TOKEN_KEY);
          await SecureStore.deleteItemAsync(REFRESH_KEY);
          setAuthCredentials(null, null);
        }
      }
      if (mounted) setReady(true);
    })();
    return () => { mounted = false; setAuthPersistence(null); };
  }, []);

  const value = useMemo<AuthContextValue>(() => ({
    ready,
    token,
    user,
    async login(email, password, role = "citizen") {
      const result = await civicConnectApi.login(email.trim(), password, role);
      setAuthCredentials(result.data.access_token, result.data.refresh_token);
      await SecureStore.setItemAsync(TOKEN_KEY, result.data.access_token);
      await SecureStore.setItemAsync(REFRESH_KEY, result.data.refresh_token);
      setTokenState(result.data.access_token);
      setUser(result.data.user);
      return result.data.user;
    },
    async logout() {
      try {
        if (token) await civicConnectApi.logout(await SecureStore.getItemAsync(REFRESH_KEY));
      } finally {
        await SecureStore.deleteItemAsync(TOKEN_KEY);
        await SecureStore.deleteItemAsync(REFRESH_KEY);
        setAuthCredentials(null, null);
        setTokenState(null);
        setUser(null);
      }
    },
  }), [ready, token, user]);

  return <AuthContext.Provider value={value}>{children}</AuthContext.Provider>;
}

export function useAuth(): AuthContextValue {
  const value = useContext(AuthContext);
  if (!value) throw new Error("useAuth must be used inside AuthProvider");
  return value;
}
