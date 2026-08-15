import { Pressable, StyleSheet, Text, View } from "react-native";

export function StatusView({ message, onRetry }: { message: string; onRetry?: () => void }) {
  return (
    <View style={styles.container}>
      <Text style={styles.title}>Unable to load CivicConnect</Text>
      <Text style={styles.message}>{message}</Text>
      {onRetry ? <Pressable onPress={onRetry} style={styles.button}><Text style={styles.buttonText}>Try again</Text></Pressable> : null}
    </View>
  );
}

const styles = StyleSheet.create({
  container: { padding: 20, borderRadius: 18, backgroundColor: "#fff0ef", gap: 8 },
  title: { color: "#9b2c2c", fontSize: 16, fontWeight: "800" },
  message: { color: "#6b3333", lineHeight: 20 },
  button: { alignSelf: "flex-start", paddingVertical: 10, paddingHorizontal: 14, borderRadius: 10, backgroundColor: "#9b2c2c" },
  buttonText: { color: "#fff", fontWeight: "800" },
});
