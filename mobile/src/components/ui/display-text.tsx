import { Platform, Text, type TextProps } from 'react-native';

type Props = TextProps & {
  className?: string;
};

const displayFamily = Platform.select({
  ios: 'Georgia',
  android: 'serif',
  default: 'Georgia',
});

export function DisplayText({ style, ...props }: Props) {
  return <Text {...props} style={[{ fontFamily: displayFamily, fontWeight: '500', letterSpacing: -0.8 }, style]} />;
}
