import { useState } from "react";
import { ActivityIndicator, Pressable, StyleSheet, Text, TextInput, View } from "react-native";
import { useRouter } from "expo-router";
import { useAuth } from "../src/auth/AuthProvider";
import { appConfig } from "../src/config";

const roles = [
  { value: "citizen", label: "Citizen", hint: "Report and track city issues" },
  { value: "worker", label: "Field worker", hint: "Receive and complete assignments" },
] as const;

export default function LoginScreen() {
  const router = useRouter();
  const { login } = useAuth();
  const [role, setRole] = useState<(typeof roles)[number]["value"]>("citizen");
  const [email, setEmail] = useState("");
  const [password, setPassword] = useState("");
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);

  async function submit(): Promise<void> {
    setBusy(true);
    setError(null);
    try {
      const user = await login(email, password, role);
      if (user.role === "worker") router.replace("/(worker)");
      else if (user.role === "admin") router.replace("/unsupported");
      else router.replace("/(tabs)");
    } catch (reason) {
      setError(reason instanceof Error ? reason.message : "Unable to sign in.");
    } finally {
      setBusy(false);
    }
  }

  return (
    <View style={styles.safe}>
      <View style={styles.brand}><View style={styles.mark}><Text style={styles.markText}>C</Text></View><View><Text style={styles.brandName}>CivicConnect</Text><Text style={styles.brandSub}>City issues, closed-loop</Text></View></View>
      <View style={styles.hero}><Text style={styles.eyebrow}>NATIVE FIELD APP</Text><Text style={styles.title}>Make the report. See the resolution.</Text><Text style={styles.subtitle}>The mobile client shares live issues, assignments, status history, and AI analysis with the CivicConnect web portal.</Text></View>
      <View style={styles.card}>
        <Text style={styles.cardTitle}>Sign in to continue</Text>
        <View style={styles.roleRow}>{roles.map((item) => <Pressable key={item.value} onPress={() => setRole(item.value)} style={[styles.role, role === item.value && styles.roleSelected]}><Text style={[styles.roleLabel, role === item.value && styles.roleLabelSelected]}>{item.label}</Text><Text style={[styles.roleHint, role === item.value && styles.roleHintSelected]}>{item.hint}</Text></Pressable>)}</View>
        <Text style={styles.label}>Email</Text>
        <TextInput value={email} onChangeText={setEmail} placeholder="you@example.com" autoCapitalize="none" autoComplete="email" keyboardType="email-address" style={styles.input} />
        <Text style={styles.label}>Password</Text>
        <TextInput value={password} onChangeText={setPassword} placeholder="Your password" secureTextEntry autoComplete="password" style={styles.input} />
        {error ? <Text style={styles.error}>{error}</Text> : null}
        <Pressable onPress={() => void submit()} disabled={busy || !email.trim() || !password} style={[styles.button, (busy || !email.trim() || !password) && styles.disabled]}>{busy ? <ActivityIndicator color="#fff" /> : <Text style={styles.buttonText}>Sign in as {role === "worker" ? "field worker" : "citizen"}</Text>}</Pressable>
      </View>
      <Text style={styles.origin}>Connected backend: {appConfig.apiBaseUrl}</Text>
      <Text style={styles.note}>Administrators continue using the web portal. This native app is intentionally focused on the citizen and field-worker workflows.</Text>
    </View>
  );
}

const styles = StyleSheet.create({
  safe: { flex: 1, padding: 22, justifyContent: "center", gap: 16, backgroundColor: "#f6f7fb" },
  brand: { flexDirection: "row", alignItems: "center", gap: 10 },
  mark: { width: 42, height: 42, borderRadius: 14, alignItems: "center", justifyContent: "center", backgroundColor: "#4f46e5" },
  markText: { color: "#fff", fontSize: 24, fontWeight: "900" },
  brandName: { color: "#111827", fontSize: 19, fontWeight: "900" },
  brandSub: { color: "#7a8494", fontSize: 11, fontWeight: "700", marginTop: 2 },
  hero: { gap: 7, marginTop: 8 },
  eyebrow: { color: "#4f46e5", fontSize: 11, fontWeight: "900", letterSpacing: 1.2 },
  title: { color: "#111827", fontSize: 31, lineHeight: 36, fontWeight: "900", letterSpacing: -0.8 },
  subtitle: { color: "#687386", lineHeight: 20 },
  card: { padding: 18, borderRadius: 20, gap: 10, backgroundColor: "#fff", borderWidth: 1, borderColor: "#e8eaf0" },
  cardTitle: { color: "#111827", fontSize: 18, fontWeight: "900", marginBottom: 2 },
  roleRow: { flexDirection: "row", gap: 8, marginBottom: 6 },
  role: { flex: 1, padding: 10, borderRadius: 12, borderWidth: 1, borderColor: "#d9deea", backgroundColor: "#fff", gap: 3 },
  roleSelected: { borderColor: "#4f46e5", backgroundColor: "#eef2ff" },
  roleLabel: { color: "#374151", fontWeight: "900", fontSize: 12 },
  roleLabelSelected: { color: "#3730a3" },
  roleHint: { color: "#7a8494", fontSize: 10, lineHeight: 14 },
  roleHintSelected: { color: "#6366f1" },
  label: { color: "#374151", fontSize: 12, fontWeight: "800", marginTop: 3 },
  input: { minHeight: 48, paddingHorizontal: 14, borderRadius: 12, borderWidth: 1, borderColor: "#d9deea", color: "#111827", backgroundColor: "#fff" },
  error: { color: "#b42318", lineHeight: 18 },
  button: { minHeight: 50, borderRadius: 14, alignItems: "center", justifyContent: "center", backgroundColor: "#4f46e5", marginTop: 4 },
  buttonText: { color: "#fff", fontWeight: "900" },
  disabled: { opacity: 0.45 },
  origin: { color: "#7a8494", fontSize: 10, textAlign: "center" },
  note: { color: "#92400e", padding: 12, borderRadius: 12, backgroundColor: "#fff7ed", fontSize: 12, lineHeight: 17, textAlign: "center" },
});
